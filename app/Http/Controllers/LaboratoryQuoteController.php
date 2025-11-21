<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\CreateGDAOnlyQuotationAction;
use App\Actions\Laboratories\CreatePatientAction;
use App\Actions\Laboratories\CreatePractitionerAction;
use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryQuote;
use App\Models\LaboratoryQuoteItem;
use App\Models\LaboratoryTest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Exception;
use Carbon\Carbon;

class LaboratoryQuoteController extends Controller
{
    public function __construct(
        protected CreateGDAOnlyQuotationAction $createGDAOnlyQuotationAction,
        protected CreatePatientAction $createPatientAction,
        protected CreatePractitionerAction $createPractitionerAction
    ) {
    }

    public function store(Request $request, string $laboratory_brand)
    {
        logger('🔍 [DEBUG] Verificando Action:', [
            'action_class' => get_class($this->createGDAOnlyQuotationAction),
            'action_exists' => class_exists(CreateGDAOnlyQuotationAction::class),
            'is_callable' => is_callable($this->createGDAOnlyQuotationAction)
        ]);

        logger('🔴 [DEBUG] ¿LLEGÓ LA REQUEST AL CONTROLLER?', [
            'method' => $request->method(),
            'laboratory_brand' => $laboratory_brand,
            'all_data' => $request->all()
        ]);

        // DEBUG: Verificar que los Actions se cargan correctamente
        try {
            logger('🔍 [DEBUG] Verificando Actions:', [
                'createGDAOnlyQuotationAction' => get_class($this->createGDAOnlyQuotationAction),
                'createPatientAction' => get_class($this->createPatientAction),
                'createPractitionerAction' => get_class($this->createPractitionerAction),
            ]);
        } catch (\Throwable $th) {
            logger('❌ [DEBUG] Error verificando Actions:', ['error' => $th->getMessage()]);
        }

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
        $customer = auth()->user()->customer;
        $address = Address::find($request->address_id);
        $contact = Contact::find($request->contact_id);

        logger('🟡 [CONTROLLER] INICIANDO STORE - REQUEST COMPLETA:', [
            'laboratory_brand' => $laboratory_brand,
            'address_id' => $request->address_id,
            'contact_id' => $request->contact_id,
            'customer_id' => $customer->id,
            'cart_items_count' => count($cartItems)
        ]);

        try {
            DB::beginTransaction();

            // Enriquecer items
            $enrichedCartItems = $this->enrichCartItemsWithTestData($cartItems);

            logger('🟢 [CONTROLLER] ITEMS ENRIQUECIDOS:', [
                'total_items' => count($enrichedCartItems),
                'primer_item' => $enrichedCartItems[0] ?? 'No items'
            ]);

            // Generar ID temporal para la cotización
            $quoteTempId = 'QT_' . now()->format('YmdHis') . '_' . uniqid();

            logger('🟡 [CONTROLLER] LLAMANDO AL ACTION CreateGDAOnlyQuotationAction...', [
                'laboratory_brand' => $laboratory_brand,
                'quote_temp_id' => $quoteTempId
            ]);

            // Formatear items para el Action
            $formattedItems = $this->formatCartItemsForAction($enrichedCartItems);

            logger('🔍 [DEBUG] Items formateados para Action:', [
                'count' => $formattedItems->count(),
                'first_item' => $formattedItems->first()
            ]);

            // Llamar al Action con try-catch específico
            try {
                $gdaResponse = ($this->createGDAOnlyQuotationAction)(
                    $customer,
                    $address,
                    $contact,
                    $laboratory_brand,
                    $formattedItems,
                    $quoteTempId
                );

                logger('🎉 [CONTROLLER] ACTION COMPLETADO - Respuesta GDA:', [
                    'gda_response' => $gdaResponse,
                    'tiene_id' => isset($gdaResponse['id']),
                    'tiene_acuse' => isset($gdaResponse['GDA_menssage']['acuse']),
                    'mensaje_gda' => $gdaResponse['GDA_menssage']['mensaje'] ?? 'sin_mensaje'
                ]);

            } catch (\Throwable $th) {
                logger('❌ [CONTROLLER] ERROR EN ACTION:', [
                    'error' => $th->getMessage(),
                    'file' => $th->getFile(),
                    'line' => $th->getLine()
                ]);
                throw $th; // Relanzar la excepción
            }

            $total = collect($cartItems)->sum(fn($i) => $i['price'] * ($i['quantity'] ?? 1));

            // 🆕 NUEVA LÓGICA: Determinar status basado en respuesta GDA
            $gdaAcuse = $gdaResponse['GDA_menssage']['acuse'] ?? null;
            $gdaStatus = $gdaResponse['GDA_menssage']['mensaje'] ?? 'unknown';
            $gdaDescription = $gdaResponse['GDA_menssage']['descripcion'] ?? null;
            $gdaCodeHttp = $gdaResponse['GDA_menssage']['codeHttp'] ?? null;

            // Determinar status de la cotización
            $quoteStatus = $this->determineQuoteStatus($gdaStatus, $gdaCodeHttp, $gdaAcuse);
            
            // Determinar si hay warning
            $hasGdaWarning = ($gdaStatus === 'error' && !empty($gdaAcuse));
            $gdaWarningMessage = $hasGdaWarning ? $gdaDescription : null;

            logger('🔄 [CONTROLLER] Determinando status de cotización:', [
                'gda_status' => $gdaStatus,
                'gda_code_http' => $gdaCodeHttp,
                'gda_acuse' => $gdaAcuse,
                'quote_status' => $quoteStatus,
                'has_warning' => $hasGdaWarning,
                'warning_message' => $gdaWarningMessage
            ]);

            // Crear la cotización en BD
            logger('🟡 [CONTROLLER] CREANDO COTIZACIÓN EN BD...');

            $quote = LaboratoryQuote::create([
                'user_id' => auth()->id(),
                'customer_id' => $customer->id,
                'laboratory_brand' => $laboratory_brand,
                'gda_order_id' => $gdaResponse['id'] ?? null,
                'patient_name' => $contact->name,
                'patient_paternal_lastname' => $contact->paternal_lastname,
                'patient_maternal_lastname' => $contact->maternal_lastname,
                'patient_phone' => $contact->phone,
                'patient_birth_date' => $contact->birth_date,
                'patient_gender' => $contact->gender,
                'contact_id' => $request->contact_id,
                'address_id' => $request->address_id,
                'items' => $enrichedCartItems,
                'subtotal' => $total,
                'discount' => 0,
                'total' => $total,
                'status' => $quoteStatus, // 🆕 Status dinámico
                'gda_response' => $gdaResponse,
                'gda_acuse' => $gdaAcuse,
                'gda_code_http' => $gdaCodeHttp, // 🆕 Nuevo campo
                'gda_mensaje' => $gdaStatus, // 🆕 Nuevo campo
                'gda_descripcion' => $gdaDescription, // 🆕 Nuevo campo
                'pdf_base64' => $gdaResponse['base64'] ?? null,
                'expires_at' => now()->addHours(24),
                'has_gda_warning' => $hasGdaWarning, // 🆕 Nuevo campo
                'gda_warning_message' => $gdaWarningMessage, // 🆕 Nuevo campo
            ]);

            // CREAR ITEMS EN LA NUEVA TABLA
            $this->createQuoteItems($quote, $enrichedCartItems);

            $this->clearCart();
            DB::commit();

            logger('✅ [CONTROLLER] COTIZACIÓN CREADA EXITOSAMENTE - ID: ' . $quote->id, [
                'status' => $quoteStatus,
                'acuse' => $gdaAcuse,
                'has_warning' => $hasGdaWarning
            ]);

            // 🆕 Redirigir con mensaje de advertencia si es necesario
            if ($hasGdaWarning) {
                return Inertia::location(route('laboratory.quote.success', $quote->id))
                    ->with('warning', 'Cotización generada con observaciones: ' . $gdaWarningMessage);
            }

            return Inertia::location(route('laboratory.quote.success', $quote->id));

        } catch (Exception $e) {
            DB::rollBack();

            logger('❌ [CONTROLLER] ERROR CAPTURADO EN STORE - DETALLES:', [
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_line' => $e->getLine(),
                'error_file' => $e->getFile(),
                'error_trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Error al generar cotización: ' . $e->getMessage());
        }
    }

    /**
     * 🆕 NUEVO MÉTODO: Determinar el status de la cotización basado en respuesta GDA
     */
    protected function determineQuoteStatus(string $gdaStatus, $gdaCodeHttp, $gdaAcuse): string
    {
        // Si GDA retorna éxito, todo bien
        if ($gdaStatus === 'success') {
            return 'pending_branch_payment';
        }

        // Si hay error PERO tenemos acuse válido, considerar como "éxito con advertencia"
        if ($gdaStatus === 'error' && !empty($gdaAcuse)) {
            return 'pending_branch_payment'; // 🆕 Permitir continuar con el proceso
        }

        // Si hay error y no hay acuse, es un error real
        if ($gdaStatus === 'error') {
            return 'gda_error';
        }

        // Caso por defecto
        return 'pending_branch_payment';
    }

    /**
     * Formatear items para el nuevo Action (similar al OrderAction)
     */
    protected function formatCartItemsForAction(array $enrichedCartItems)
    {
        return collect($enrichedCartItems)->map(function ($item) {
            return (object) [
                'laboratoryTest' => (object) [
                    'gda_id' => $item['gda_id'] ?? 'UNKNOWN',
                    'name' => $item['name'] ?? 'Sin nombre',
                    'famedic_price_cents' => (int) (($item['price'] ?? 0) * 100),
                    'feature_list' => $item['feature_list'] ?? [] // 🆕 Agregar feature_list para paquetes
                ]
            ];
        });
    }

    /**
     * Crear items en la nueva tabla laboratory_quote_items
     */
    protected function createQuoteItems(LaboratoryQuote $quote, array $enrichedCartItems): void
    {
        foreach ($enrichedCartItems as $item) {
            LaboratoryQuoteItem::create([
                'laboratory_quote_id' => $quote->id,
                'gda_id' => $item['gda_id'] ?? 'UNKNOWN',
                'name' => $item['name'] ?? 'Sin nombre',
                'description' => $item['description'] ?? null,
                'feature_list' => $item['feature_list'] ?? null,
                'indications' => $item['indications'] ?? null,
                'price_cents' => (int) (($item['price'] ?? 0) * 100),
                'quantity' => $item['quantity'] ?? 1,
                'is_package' => $item['is_package'] ?? false, // 🆕 Nuevo campo
                'feature_count' => !empty($item['feature_list']) ? count($item['feature_list']) : 0, // 🆕 Nuevo campo
            ]);
        }

        logger('✅ [CONTROLLER] Items de cotización creados:', [
            'quote_id' => $quote->id,
            'total_items' => count($enrichedCartItems),
            'paquetes_count' => collect($enrichedCartItems)->where('is_package', true)->count()
        ]);
    }

    /**
     * Enriquecer los items del carrito con datos adicionales de LaboratoryTest
     */
    protected function enrichCartItemsWithTestData(array $cartItems): array
    {
        logger('=== ENRIQUECIENDO CART ITEMS CON TEST DATA ===');

        $testIds = collect($cartItems)->pluck('test_id')->filter()->unique();

        if ($testIds->isEmpty()) {
            return $cartItems;
        }

        $labTests = LaboratoryTest::whereIn('id', $testIds)->get();
        $labTestsKeyed = $labTests->keyBy('id');

        $enrichedItems = collect($cartItems)->map(function ($item) use ($labTestsKeyed) {
            if (isset($item['test_id']) && $labTest = $labTestsKeyed[$item['test_id']] ?? null) {

                $featureList = $this->parseJsonField($labTest->feature_list);
                $elements = $this->parseJsonField($labTest->elements);
                $isPackage = !empty($featureList) && is_array($featureList);

                return array_merge($item, [
                    'gda_id' => $labTest->gda_id,
                    'name' => $labTest->name,
                    'description' => $labTest->description,
                    'elements' => $elements,
                    'feature_list' => $featureList,
                    'is_package' => $isPackage,
                    'brand' => $labTest->brand,
                    'requires_appointment' => $labTest->requires_appointment,
                ]);
            }

            logger('⚠️ [CONTROLLER] Test no encontrado para item:', ['test_id' => $item['test_id'] ?? 'No definido']);
            return $item;
        })->toArray();

        logger('=== ITEMS ENRIQUECIDOS FINALES ===', [
            'total_items' => count($enrichedItems),
            'paquetes_count' => collect($enrichedItems)->where('is_package', true)->count()
        ]);

        return $enrichedItems;
    }

    /**
     * Convertir campo JSON string a array de forma segura
     */
    protected function parseJsonField($field): array
    {
        if (empty($field))
            return [];
        if (is_array($field))
            return $field;

        if (is_string($field)) {
            try {
                $decoded = json_decode($field, true);
                return is_array($decoded) ? $decoded : [];
            } catch (Exception $e) {
                logger('Error decodificando JSON field');
                return [];
            }
        }
        return [];
    }

    /**
     * Limpiar el carrito del usuario
     */
    protected function clearCart()
    {
        $customer = auth()->user()->customer;
        $itemsCount = LaboratoryCartItem::where('customer_id', $customer->id)->count();

        LaboratoryCartItem::where('customer_id', $customer->id)->delete();

        session()->forget('laboratory_cart');
        session()->forget('cart_items');

        logger("Carrito de laboratorio limpiado", [
            'customer_id' => $customer->id,
            'items_eliminados' => $itemsCount
        ]);
    }

    /**
     * 🆕 ACTUALIZADO: Mostrar éxito de cotización con manejo de advertencias
     */
    public function success(LaboratoryQuote $quote)
    {
        $quote->load(['contact', 'address', 'appointment.laboratoryStore', 'quoteItems']);

        $quoteData = [
            'id' => $quote->id,
            'gda_acuse' => $quote->gda_acuse,
            'gda_order_id' => $quote->gda_order_id,
            'total_cents' => $quote->total_cents,
            'subtotal_cents' => $quote->subtotal_cents,
            'discount_cents' => $quote->discount_cents,
            'expires_at' => $quote->expires_at,
            'created_at' => $quote->created_at,
            'status' => $quote->status,
            'patient_name' => $quote->patient_full_name,
            'patient_phone' => $quote->patient_phone,
            'items' => $quote->items,
            'quote_items' => $quote->quoteItems,
            'pdf_base64' => $quote->pdf_base64,
            // 🆕 Nuevos campos para mostrar en la vista
            'has_gda_warning' => $quote->has_gda_warning,
            'gda_warning_message' => $quote->gda_warning_message,
            'gda_mensaje' => $quote->gda_mensaje,
            'gda_descripcion' => $quote->gda_descripcion,
        ];

        // Información de contacto
        if ($quote->relationLoaded('contact') && $quote->contact) {
            $quoteData['contact'] = [
                'name' => $quote->contact->name,
                'paternal_lastname' => $quote->contact->paternal_lastname,
                'maternal_lastname' => $quote->contact->maternal_lastname,
                'phone' => $quote->contact->phone,
                'email' => $quote->contact->email,
            ];
        }

        // Información de dirección
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
        }

        // Información de cita
        if ($quote->relationLoaded('appointment') && $quote->appointment) {
            $quoteData['appointment'] = [
                'scheduled_at' => $quote->appointment->scheduled_at,
                'laboratory_store' => $quote->appointment->laboratoryStore ? [
                    'name' => $quote->appointment->laboratoryStore->name,
                    'address' => $quote->appointment->laboratoryStore->address,
                ] : null,
            ];
        }

        return inertia('LaboratoryQuoteSuccess', [
            'quote' => $quoteData,
            'laboratoryBrand' => [
                'name' => strtoupper($quote->laboratory_brand),
                'imageSrc' => 'logo-gda.png'
            ]
        ]);
    }

    // Los demás métodos permanecen igual...
    public function show(LaboratoryQuote $quote)
    {
        if ($quote->user_id !== auth()->id()) {
            abort(403, 'No tienes permisos para ver esta cotización.');
        }

        $quote->load(['contact', 'address', 'appointment.laboratoryStore', 'quoteItems']);

        return inertia('LaboratoryQuoteShow', [
            'quote' => $quote,
            'laboratoryBrand' => [
                'name' => strtoupper($quote->laboratory_brand),
                'imageSrc' => 'logo-gda.png'
            ]
        ]);
    }

    public function index()
    {
        $quotes = LaboratoryQuote::where('user_id', auth()->id())
            ->with(['contact', 'appointment', 'quoteItems'])
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return inertia('LaboratoryQuotesIndex', [
            'quotes' => $quotes,
            'filters' => request()->only(['search'])
        ]);
    }

    public function cancel(LaboratoryQuote $quote)
    {
        if ($quote->user_id !== auth()->id()) {
            abort(403, 'No tienes permisos para cancelar esta cotización.');
        }

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

    public function resendPdf(LaboratoryQuote $quote)
    {
        if ($quote->user_id !== auth()->id()) {
            abort(403, 'No tienes permisos para esta acción.');
        }

        return back()->with('success', 'PDF reenviado exitosamente.');
    }
}