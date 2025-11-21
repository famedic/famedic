<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\CreateGDAQuotationAction;
use App\Http\Controllers\Controller;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryQuote;
use App\Models\LaboratoryTest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Exception;

class LaboratoryQuoteControllerVersionSimple extends Controller
{
    public function __construct(
        protected CreateGDAQuotationAction $createGDAQuotationAction
    ) {
    }

    // En el método store del Controller, agrega estos logs:
    public function store(Request $request, string $laboratory_brand)
    {
        
        // LOG TEMPORAL PARA CONFIRMAR SI LLEGA LA REQUEST
        logger('🔴 [DEBUG] ¿LLEGÓ LA REQUEST AL CONTROLLER?', [
            'method' => $request->method(),
            'laboratory_brand' => $laboratory_brand,
            'all_data' => $request->all(),
            'headers' => $request->headers->all(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        $request->validate([
            'address_id' => 'required|exists:addresses,id',
            'contact_id' => 'required|exists:contacts,id',
            'cart_items' => 'required|array|min:1',
            'cart_items.*.test_id' => 'required|integer|exists:laboratory_tests,id',
            'cart_items.*.name' => 'required|string',
            'cart_items.*.price' => 'required|numeric',
            'cart_items.*.quantity' => 'nullable|integer|min:1',
        ]);

        $cartItems = $request->input('cart_items');

        // LOG CRÍTICO 1: Ver EXACTAMENTE qué llega del frontend
        logger('🟡 [CONTROLLER] INICIANDO STORE - REQUEST COMPLETA:', [
            'laboratory_brand' => $laboratory_brand,
            'address_id' => $request->address_id,
            'contact_id' => $request->contact_id,
            'cart_items_raw' => $cartItems,
            'user_id' => auth()->id(),
            'customer_id' => auth()->user()->customer->id ?? 'NO_CUSTOMER'
        ]);

        try {
            DB::beginTransaction();

            // LOG CRÍTICO 2: Antes de enriquecer items
            logger('🟡 [CONTROLLER] ANTES DE ENRIQUECER ITEMS');

            // Enriquecer items
            $enrichedCartItems = $this->enrichCartItemsWithTestData($cartItems);

            // LOG CRÍTICO 3: Después de enriquecer
            logger('🟢 [CONTROLLER] ITEMS ENRIQUECIDOS:', [
                'total_items' => count($enrichedCartItems),
                'primer_item_detallado' => $enrichedCartItems[0] ?? 'No items',
                'tiene_gda_id' => isset($enrichedCartItems[0]['gda_id']),
                'tiene_feature_list' => isset($enrichedCartItems[0]['feature_list']),
                'feature_list_count' => count($enrichedCartItems[0]['feature_list'] ?? [])
            ]);

            // LOG CRÍTICO 4: Antes de llamar al Action
            logger('🟡 [CONTROLLER] LLAMANDO AL ACTION CreateGDAQuotationAction...', [
                'laboratory_brand' => $laboratory_brand,
                'items_count' => count($enrichedCartItems)
            ]);

            //dd($enrichedCartItems, 'DEBUG - ITEMS ENRIQUECIDOS ANTES DEL ACTION');
            // Llamar al Action - ESTA ES LA LÍNEA CLAVE
            $gdaResponse = ($this->createGDAQuotationAction)($enrichedCartItems, $laboratory_brand);
            //dd($gdaResponse, 'DEBUG - RESPUESTA DEL ACTION');
            // LOG CRÍTICO 5: Si llegamos aquí, el Action funcionó
            logger('🎉 [CONTROLLER] ACTION COMPLETADO EXITOSAMENTE - Respuesta GDA:', [
                'tiene_acuse' => isset($gdaResponse['GDA_menssage']['acuse']),
                'tiene_pdf' => isset($gdaResponse['base64']),
                'acuse' => $gdaResponse['GDA_menssage']['acuse'] ?? 'NO_ACUSE',
                'respuesta_completa' => $gdaResponse
            ]);

            $total = collect($cartItems)->sum(fn($i) => $i['price'] * ($i['quantity'] ?? 1));

            // LOG CRÍTICO 6: Antes de crear la cotización
            logger('🟡 [CONTROLLER] CREANDO COTIZACIÓN EN BD...', [
                'total' => $total,
                'customer_id' => auth()->user()->customer->id
            ]);

            $quote = LaboratoryQuote::create([
                'user_id' => auth()->id(),
                'customer_id' => auth()->user()->customer->id,
                'laboratory_brand' => $laboratory_brand,
                'contact_id' => $request->contact_id,
                'address_id' => $request->address_id,
                'items' => $enrichedCartItems,
                'subtotal' => $total,
                'discount' => 0,
                'total' => $total,
                'status' => 'pending_branch_payment',
                'gda_response' => $gdaResponse,
                'gda_acuse' => $gdaResponse['GDA_menssage']['acuse'] ?? null,
                'pdf_base64' => $gdaResponse['base64'] ?? null,
                'expires_at' => now()->addHours(24),
            ]);

            $this->clearCart();
            DB::commit();

            logger('✅ [CONTROLLER] COTIZACIÓN CREADA EXITOSAMENTE - ID: ' . $quote->id);
            return Inertia::location(route('laboratory.quote.success', $quote->id));

        } catch (Exception $e) {
            DB::rollBack();

            // LOG CRÍTICO 7: Error capturado - DETALLADO
            logger('❌ [CONTROLLER] ERROR CAPTURADO EN STORE - DETALLES:', [
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_line' => $e->getLine(),
                'error_file' => $e->getFile(),
                'error_trace' => $e->getTraceAsString(), // ← ESTO ES CRÍTICO
                'request_data' => [
                    'laboratory_brand' => $laboratory_brand,
                    'address_id' => $request->address_id,
                    'contact_id' => $request->contact_id,
                    'cart_items' => $cartItems
                ]
            ]);

            return back()->with('error', 'Error al generar cotización: ' . $e->getMessage());
        }
    }

    /**
     * Enriquecer los items del carrito con datos adicionales de LaboratoryTest
     */
    /**
     * Enriquecer los items del carrito con datos adicionales de LaboratoryTest
     */
    protected function enrichCartItemsWithTestData(array $cartItems): array
    {
        logger('=== ENRIQUECIENDO CART ITEMS CON TEST DATA ===');
        logger('Cart items originales:', $cartItems);

        $testIds = collect($cartItems)->pluck('test_id')->filter()->unique();
        logger('Test IDs encontrados:', $testIds->toArray());

        if ($testIds->isEmpty()) {
            logger('No hay test IDs, retornando items originales');
            return $cartItems;
        }

        $labTests = LaboratoryTest::whereIn('id', $testIds)->get();

        logger('LaboratoryTests cargados:', [
            'cantidad' => $labTests->count(),
            'ids' => $labTests->pluck('id')->toArray()
        ]);

        // Log detallado de cada test cargado
        foreach ($labTests as $test) {
            // CORRECCIÓN: Convertir feature_list y elements de JSON string a array
            $featureList = $this->parseJsonField($test->feature_list);
            $elements = $this->parseJsonField($test->elements);

            logger("Test cargado - ID: {$test->id}, Nombre: {$test->name}", [
                'gda_id' => $test->gda_id,
                'elements' => $elements,
                'feature_list' => $featureList,
                'has_elements' => !empty($elements),
                'has_feature_list' => !empty($featureList),
                'feature_list_count' => !empty($featureList) ? count($featureList) : 0,
                'elements_count' => !empty($elements) ? count($elements) : 0
            ]);
        }

        $labTestsKeyed = $labTests->keyBy('id');

        $enrichedItems = collect($cartItems)->map(function ($item) use ($labTestsKeyed) {
            logger("Enriqueciendo item:", $item);

            if (isset($item['test_id']) && $labTest = $labTestsKeyed[$item['test_id']] ?? null) {

                // CORRECCIÓN: Convertir campos JSON a arrays
                $featureList = $this->parseJsonField($labTest->feature_list);
                $elements = $this->parseJsonField($labTest->elements);

                // Determinar si es paquete basado en feature_list
                $isPackage = !empty($featureList) && is_array($featureList);

                $enrichedItem = array_merge($item, [
                    'gda_id' => $labTest->gda_id,
                    'name' => $labTest->name,
                    'description' => $labTest->description,
                    'elements' => $elements,
                    'feature_list' => $featureList,
                    'is_package' => $isPackage,
                    'brand' => $labTest->brand,
                    'requires_appointment' => $labTest->requires_appointment,
                ]);

                logger("Item enriquecido:", [
                    'name' => $enrichedItem['name'],
                    'gda_id' => $enrichedItem['gda_id'],
                    'es_paquete' => $isPackage ? 'SÍ' : 'NO',
                    'feature_list_count' => $isPackage ? count($featureList) : 0,
                    'elements_count' => !empty($elements) ? count($elements) : 0
                ]);

                return $enrichedItem;
            }

            logger("Item NO enriquecido - test_id no encontrado:", [
                'test_id' => $item['test_id'] ?? 'No definido',
                'item' => $item
            ]);
            return $item;
        })->toArray();

        logger('=== ITEMS ENRIQUECIDOS FINALES ===', [
            'total_items' => count($enrichedItems),
            'items' => $enrichedItems
        ]);

        return $enrichedItems;
    }

    /**
     * Convertir campo JSON string a array de forma segura
     */
    protected function parseJsonField($field): array
    {
        if (empty($field)) {
            return [];
        }

        // Si ya es un array, retornarlo directamente
        if (is_array($field)) {
            return $field;
        }

        // Si es string, intentar decodificar JSON
        if (is_string($field)) {
            try {
                $decoded = json_decode($field, true);
                return is_array($decoded) ? $decoded : [];
            } catch (Exception $e) {
                logger('Error decodificando JSON field:', [
                    'field' => $field,
                    'error' => $e->getMessage()
                ]);
                return [];
            }
        }

        return [];
    }

    /**
     * Limpiar el carrito del usuario de la base de datos
     */
    protected function clearCart()
    {
        $customer = auth()->user()->customer;

        // Contar items antes de eliminar para logging
        $itemsCount = LaboratoryCartItem::where('customer_id', $customer->id)->count();

        // Eliminar todos los items del carrito del cliente
        LaboratoryCartItem::where('customer_id', $customer->id)->delete();

        // También limpiar cualquier sesión relacionada por si acaso
        session()->forget('laboratory_cart');
        session()->forget('cart_items');

        // Log para verificar que se limpió el carrito
        logger("Carrito de laboratorio limpiado", [
            'customer_id' => $customer->id,
            'items_eliminados' => $itemsCount
        ]);
    }

    public function success(LaboratoryQuote $quote)
    {
        // Cargar las relaciones necesarias
        $quote->load(['contact', 'address', 'appointment.laboratoryStore']);

        // Usar los accessors del modelo - CORREGIDO
        $quoteData = [
            'id' => $quote->id,
            'gda_acuse' => $quote->gda_acuse,
            'total_cents' => $quote->total_cents,
            'subtotal_cents' => $quote->subtotal_cents,
            'discount_cents' => $quote->discount_cents,
            'expires_at' => $quote->expires_at,
            'created_at' => $quote->created_at,
            'status' => $quote->status,
            'patient_name' => $quote->appointment?->patientFullName ?? 'Paciente',
            'items' => $quote->items,
            'pdf_base64' => $quote->pdf_base64,
        ];

        // Solo agregar contact si existe la relación y el contacto
        if ($quote->relationLoaded('contact') && $quote->contact) {
            $quoteData['contact'] = [
                'name' => $quote->contact->name,
                'paternal_lastname' => $quote->contact->paternal_lastname,
                'maternal_lastname' => $quote->contact->maternal_lastname,
                'phone' => $quote->contact->phone,
                'email' => $quote->contact->email,
            ];
        } else {
            $quoteData['contact'] = null;
        }

        // Solo agregar address si existe la relación y la dirección
        if ($quote->relationLoaded('address') && $quote->address) {
            $quoteData['address'] = [
                'street' => $quote->address->street,
                'street_number' => $quote->address->street_number,
                'interior_number' => $quote->address->interior_number,
                'neighborhood' => $quote->address->neighborhood,
                'city' => $quote->address->city,
                'state' => $quote->address->state,
                'zip_code' => $quote->address->zip_code,
                'full_address' => $quote->address->full_address,
            ];
        } else {
            $quoteData['address'] = null;
        }

        // Solo agregar appointment si existe la relación y la cita
        if ($quote->relationLoaded('appointment') && $quote->appointment) {
            $quoteData['appointment'] = [
                'scheduled_at' => $quote->appointment->scheduled_at,
                'laboratory_store' => $quote->appointment->laboratoryStore ? [
                    'name' => $quote->appointment->laboratoryStore->name,
                    'address' => $quote->appointment->laboratoryStore->address,
                ] : null,
            ];
        } else {
            $quoteData['appointment'] = null;
        }

        return inertia('LaboratoryQuoteSuccess', [
            'quote' => $quoteData,
            'laboratoryBrand' => [
                'name' => strtoupper($quote->laboratory_brand),
                'imageSrc' => 'logo-gda.png'
            ]
        ]);
    }

    /**
     * Mostrar detalles de una cotización específica
     */
    public function show(LaboratoryQuote $quote)
    {
        // Verificar que el usuario tiene permisos para ver esta cotización
        if ($quote->user_id !== auth()->id()) {
            abort(403, 'No tienes permisos para ver esta cotización.');
        }

        $quote->load(['contact', 'address', 'appointment.laboratoryStore']);

        return inertia('LaboratoryQuoteShow', [
            'quote' => $quote,
            'laboratoryBrand' => [
                'name' => strtoupper($quote->laboratory_brand),
                'imageSrc' => 'logo-gda.png'
            ]
        ]);
    }

    /**
     * Listar todas las cotizaciones del usuario
     */
    public function index()
    {
        $quotes = LaboratoryQuote::where('user_id', auth()->id())
            ->with(['contact', 'appointment'])
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return inertia('LaboratoryQuotesIndex', [
            'quotes' => $quotes,
            'filters' => request()->only(['search'])
        ]);
    }

    /**
     * Cancelar una cotización
     */
    public function cancel(LaboratoryQuote $quote)
    {
        // Verificar que el usuario tiene permisos para cancelar esta cotización
        if ($quote->user_id !== auth()->id()) {
            abort(403, 'No tienes permisos para cancelar esta cotización.');
        }

        // Solo se pueden cancelar cotizaciones pendientes
        if ($quote->status !== 'pending_branch_payment') {
            return back()->with('error', 'Solo se pueden cancelar cotizaciones pendientes de pago.');
        }

        try {
            $quote->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);

            return back()->with('success', 'Cotización cancelada exitosamente.');

        } catch (Exception $e) {
            logger('Error al cancelar cotización:', [
                'quote_id' => $quote->id,
                'error' => $e->getMessage()
            ]);

            return back()->with('error', 'Error al cancelar la cotización: ' . $e->getMessage());
        }
    }

    /**
     * Reenviar PDF por email (si está implementado)
     */
    public function resendPdf(LaboratoryQuote $quote)
    {
        // Verificar que el usuario tiene permisos
        if ($quote->user_id !== auth()->id()) {
            abort(403, 'No tienes permisos para esta acción.');
        }

        // Aquí iría la lógica para reenviar el PDF por email
        // Por ahora solo retornamos un mensaje

        return back()->with('success', 'PDF reenviado exitosamente.');
    }
}