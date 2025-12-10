<?php

namespace App\Http\Controllers;

use App\Services\ConstanciaFiscalService;
use App\Actions\TaxProfiles\CreateTaxProfileAction;
use App\Actions\TaxProfiles\DestroyTaxProfileAction;
use App\Actions\TaxProfiles\UpdateTaxProfileAction;
use App\Http\Requests\TaxProfiles\DestroyTaxProfileRequest;
use App\Http\Requests\TaxProfiles\EditTaxProfileRequest;
use App\Http\Requests\TaxProfiles\StoreTaxProfileRequest;
use App\Http\Requests\TaxProfiles\UpdateTaxProfileRequest;
use App\Models\Invoice;
use App\Models\LaboratoryPurchase;
use App\Models\OnlinePharmacyPurchase;
use App\Models\TaxProfile;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TaxProfileController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('TaxProfiles', [
            'taxProfiles' => $request->user()->customer->taxProfiles,
            'invoices' => Invoice::whereHasMorph(
                'invoiceable',
                [LaboratoryPurchase::class, OnlinePharmacyPurchase::class],
                function ($query) use ($request) {
                    $query->where('customer_id', $request->user()->customer->id);
                }
            )->with([
                        'invoiceable' => function ($query) {
                            $query->morphWith([
                                LaboratoryPurchase::class => ['laboratoryPurchaseItems'],
                                OnlinePharmacyPurchase::class => ['onlinePharmacyPurchaseItems'],
                            ]);
                        },
                    ])->paginate(),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('TaxProfiles', [
            'taxProfiles' => $request->user()->customer->taxProfiles,
            'invoices' => Invoice::whereHasMorph(
                'invoiceable',
                [LaboratoryPurchase::class, OnlinePharmacyPurchase::class],
                function ($query) use ($request) {
                    $query->where('customer_id', $request->user()->customer->id);
                }
            )->with([
                        'invoiceable' => function ($query) {
                            $query->morphWith([
                                LaboratoryPurchase::class => ['laboratoryPurchaseItems'],
                                OnlinePharmacyPurchase::class => ['onlinePharmacyPurchaseItems'],
                            ]);
                        },
                    ])->paginate(),
            'taxRegimes' => config('taxregimes.regimes'),
            'cfdiUses' => config('taxregimes.uses'),
        ]);
    }

    // En el método store
    public function store(StoreTaxProfileRequest $request, CreateTaxProfileAction $action)
    {
        \Log::info('=== TAX PROFILE STORE START ===');
        \Log::info('Request data:', [
            'name' => $request->name,
            'rfc' => $request->rfc,
            'zipcode' => $request->zipcode,
            'tax_regime' => $request->tax_regime,
            'cfdi_use' => $request->cfdi_use,
            'has_file' => $request->hasFile('fiscal_certificate'),
            'file_name' => $request->file('fiscal_certificate')?->getClientOriginalName(),
            'extracted_data' => $request->input('extracted_data'),
            'confirm_data' => $request->input('confirm_data'),
        ]);

        \Log::info('Session extracted data:', [
            'extracted_tax_data' => session()->get('extracted_tax_data'),
        ]);

        try {
            // Obtener datos extraídos
            $extractedData = session()->get('extracted_tax_data') ?:
                ($request->has('extracted_data') ? json_decode($request->input('extracted_data'), true) : null);

            \Log::info('Extracted data parsed:', $extractedData);

            $action(
                name: $request->name,
                rfc: $request->rfc,
                zipcode: $request->zipcode,
                taxRegime: $request->tax_regime,
                cfdiUse: $request->cfdi_use,
                fiscalCertificate: $request->file('fiscal_certificate'),
                extractedData: $extractedData
            );

            \Log::info('Action executed successfully');

            // Limpiar datos de sesión
            session()->forget('extracted_tax_data');

            return redirect()->route('tax-profiles.index')
                ->flashMessage('Perfil fiscal creado exitosamente.');

        } catch (\Exception $e) {
            \Log::error('Error in store method: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
            ]);

            return back()->withErrors(['error' => 'Error al crear el perfil fiscal: ' . $e->getMessage()]);
        }
    }

    // En el método update
    public function update(UpdateTaxProfileRequest $request, TaxProfile $taxProfile, UpdateTaxProfileAction $action)
    {
        // Obtener datos extraídos
        $extractedData = session()->get('extracted_tax_data') ?:
            ($request->has('extracted_data') ? $request->input('extracted_data') : null);

        $action(
            name: $request->name,
            rfc: $request->rfc,
            zipcode: $request->zipcode,
            taxRegime: $request->tax_regime,
            cfdiUse: $request->cfdi_use,
            taxProfile: $taxProfile,
            fiscalCertificate: $request->hasFile('fiscal_certificate') ? $request->file('fiscal_certificate') : null,
            extractedData: $extractedData // Pasar datos extraídos
        );

        // Limpiar datos de sesión
        session()->forget('extracted_tax_data');

        return redirect()->route('tax-profiles.index')
            ->flashMessage('Perfil fiscal actualizado exitosamente.');
    }

    public function edit(EditTaxProfileRequest $request, TaxProfile $taxProfile)
    {
        return Inertia::render('TaxProfiles', [
            'taxProfiles' => $request->user()->customer->taxProfiles,
            'invoices' => Invoice::whereHasMorph(
                'invoiceable',
                [LaboratoryPurchase::class, OnlinePharmacyPurchase::class],
                function ($query) use ($request) {
                    $query->where('customer_id', $request->user()->customer->id);
                }
            )->with([
                        'invoiceable' => function ($query) {
                            $query->morphWith([
                                LaboratoryPurchase::class => ['laboratoryPurchaseItems'],
                                OnlinePharmacyPurchase::class => ['onlinePharmacyPurchaseItems'],
                            ]);
                        },
                    ])->paginate(),
            'taxProfile' => $taxProfile,
            'taxRegimes' => config('taxregimes.regimes'),
            'cfdiUses' => config('taxregimes.uses'),
        ]);
    }



    public function destroy(DestroyTaxProfileRequest $request, TaxProfile $taxProfile, DestroyTaxProfileAction $action)
    {
        $action($taxProfile);

        return redirect()->route('tax-profiles.index')
            ->flashMessage('Perfil fiscal eliminado exitosamente.');
    }

    public function extractData(Request $request)
    {
        \Log::info('=== EXTRACT DATA - PASSWORD CONFIRM CHECK ===');

        // Verificar estado de password confirm
        $passwordConfirmedAt = session()->get('auth.password_confirmed_at');
        $passwordTimeout = config('auth.password_timeout', 10800);

        \Log::info('Password confirm status:', [
            'confirmed_at' => $passwordConfirmedAt,
            'current_time' => time(),
            'time_diff' => $passwordConfirmedAt ? time() - $passwordConfirmedAt : null,
            'timeout' => $passwordTimeout,
            'is_valid' => $passwordConfirmedAt && (time() - $passwordConfirmedAt < $passwordTimeout),
            'session_id' => session()->getId(),
            'user_id' => auth()->id(),
            'user_email' => auth()->user()->email ?? 'null',
            'route_middleware' => $request->route()->gatherMiddleware(),
        ]);

        try {
            // Validar archivo
            $request->validate([
                'fiscal_certificate' => 'required|file|mimes:pdf|max:5120',
            ]);

            \Log::info('Archivo recibido:', [
                'nombre' => $request->file('fiscal_certificate')->getClientOriginalName(),
                'tamaño' => $request->file('fiscal_certificate')->getSize(),
            ]);

            // Usar el servicio real para procesar el PDF
            $service = app(ConstanciaFiscalService::class);
            $resultado = $service->procesarConstancia($request->file('fiscal_certificate'));

            if (!$resultado['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $resultado['error']
                ], 422);
            }

            return response()->json([
                'success' => true,
                'data' => $resultado['data'],
                'debug' => [
                    'password_confirm_required' => false,
                    'password_confirmed_at' => $passwordConfirmedAt,
                    'session_id' => session()->getId(),
                    'processing_time' => microtime(true) - LARAVEL_START,
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Error de validación:', $e->errors());

            return response()->json([
                'success' => false,
                'message' => 'Error de validación: ' . implode(', ', $e->validator->errors()->all())
            ], 422);

        } catch (\Exception $e) {
            \Log::error('Error en extractData: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            // Fallback: devolver datos de prueba si hay error
            $testData = [
                'rfc' => 'XAXX010101000',
                'nombre' => 'PUBLICO EN GENERAL',
                'razon_social' => 'PUBLICO EN GENERAL',
                'codigo_postal' => '64000',
                'regimen_fiscal' => 'Régimen de Incorporación Fiscal',
                'tipo_persona' => 'fisica',
                'fecha_emision' => now()->format('Y-m-d'),
                'estatus_sat' => 'Vigente',
                'tipo_persona_confianza' => 95,
                'error_original' => $e->getMessage(),
            ];

            return response()->json([
                'success' => true, // Aún success para que el frontend pueda usar los datos
                'data' => $testData,
                'warning' => 'Se usaron datos de prueba debido a un error en el procesamiento: ' . $e->getMessage()
            ]);
        }
    }


    public function testService(Request $request)
    {
        try {
            \Log::info('=== INICIANDO PRUEBA DE SERVICIO ===');

            // Verificar autenticación
            /*if (!auth()->check()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }*/

            // Verificar si el servicio existe
            \Log::info('Verificando clase ConstanciaFiscalService...');
            if (!class_exists(\App\Services\ConstanciaFiscalService::class)) {
                \Log::error('Clase ConstanciaFiscalService no existe');
                return response()->json([
                    'success' => false,
                    'message' => 'Clase ConstanciaFiscalService no existe'
                ], 500);
            }

            // Verificar si smalot/pdfparser está instalado
            \Log::info('Verificando librería PDF Parser...');
            if (!class_exists('Smalot\PdfParser\Parser')) {
                \Log::warning('Librería smalot/pdfparser no está instalada');
                return response()->json([
                    'success' => false,
                    'message' => 'Librería smalot/pdfparser no está instalada. Ejecuta: composer require smalot/pdfparser'
                ], 500);
            }

            // Crear instancia del servicio
            \Log::info('Creando instancia del servicio...');
            $service = app(\App\Services\ConstanciaFiscalService::class);

            // Verificar que se creó correctamente
            if (!$service) {
                \Log::error('No se pudo crear instancia del servicio');
                return response()->json([
                    'success' => false,
                    'message' => 'Error al crear instancia del servicio'
                ], 500);
            }

            \Log::info('Servicio creado exitosamente: ' . get_class($service));

            return response()->json([
                'success' => true,
                'message' => '¡Servicio listo y funcionando!',
                'data' => [
                    'service_class' => get_class($service),
                    'parser_installed' => true,
                    'user_authenticated' => auth()->check(),
                    'user_id' => auth()->id(),
                    'timestamp' => now()->toDateTimeString(),
                    'laravel_version' => app()->version(),
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error en testService: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'error_details' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            ], 500);
        }
    }

    public function extractDataDebug(Request $request)
    {
        \Log::info('=== DEBUG EXTRACT DATA START ===');
        \Log::info('Request method:', ['method' => $request->method()]);
        \Log::info('Request headers:', $request->headers->all());
        \Log::info('Request content type:', ['type' => $request->header('Content-Type')]);
        \Log::info('Accept header:', ['accept' => $request->header('Accept')]);
        \Log::info('Is AJAX?', ['ajax' => $request->ajax()]);
        \Log::info('Wants JSON?', ['wantsJson' => $request->wantsJson()]);
        \Log::info('User authenticated?', ['auth' => auth()->check()]);
        \Log::info('User ID:', ['id' => auth()->id()]);
        \Log::info('Session ID:', ['session' => session()->getId()]);

        // Forzar respuesta JSON para debug
        if (!$request->wantsJson() && !$request->ajax()) {
            \Log::warning('Request is not AJAX or JSON request');
        }

        try {
            // Validar manualmente para ver errores
            if (!$request->hasFile('fiscal_certificate')) {
                \Log::error('No file in request');
                return response()->json([
                    'success' => false,
                    'message' => 'No se recibió ningún archivo',
                    'debug' => 'No file found in request'
                ], 400);
            }

            $file = $request->file('fiscal_certificate');

            \Log::info('File received:', [
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getMimeType(),
            ]);

            // Validaciones básicas
            if ($file->getMimeType() !== 'application/pdf') {
                \Log::error('Invalid file type:', ['type' => $file->getMimeType()]);
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo debe ser PDF',
                    'debug' => 'Invalid file type: ' . $file->getMimeType()
                ], 422);
            }

            if ($file->getSize() > 5 * 1024 * 1024) {
                \Log::error('File too large:', ['size' => $file->getSize()]);
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo no debe superar 5MB',
                    'debug' => 'File size: ' . $file->getSize()
                ], 422);
            }

            // Datos de prueba
            $testData = [
                'rfc' => 'XAXX010101000',
                'nombre' => 'PUBLICO EN GENERAL',
                'razon_social' => 'PUBLICO EN GENERAL',
                'codigo_postal' => '64000',
                'regimen_fiscal' => 'Régimen de Incorporación Fiscal',
                'tipo_persona' => 'fisica',
                'fecha_emision' => now()->format('Y-m-d H:i:s'),
                'estatus_sat' => 'Vigente',
                'tipo_persona_confianza' => 95,
                'debug_info' => [
                    'timestamp' => now()->toDateTimeString(),
                    'file_name' => $file->getClientOriginalName(),
                    'file_size' => $file->getSize(),
                    'session_id' => session()->getId(),
                    'user_id' => auth()->id(),
                ]
            ];

            \Log::info('Returning test data:', $testData);

            return response()->json([
                'success' => true,
                'data' => $testData,
                'message' => 'Datos extraídos exitosamente (modo debug)'
            ]);

        } catch (\Exception $e) {
            \Log::error('Exception in extractDataDebug: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno: ' . $e->getMessage(),
                'debug' => [
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            ], 500);
        }
    }
}
