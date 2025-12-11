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
        ]);
    }


    public function store(StoreTaxProfileRequest $request, CreateTaxProfileAction $action)
    {
        \Log::info('=== TAX PROFILE STORE ===');

        try {
            // Obtener datos extraídos
            $extractedData = null;
            if ($request->has('extracted_data')) {
                $extractedData = json_decode($request->input('extracted_data'), true);
            }

            // Ejecutar el action
            $taxProfile = $action(
                name: $request->name,
                rfc: $request->rfc,
                zipcode: $request->zipcode,
                taxRegime: $request->tax_regime,
                cfdiUse: $request->cfdi_use ?? 'G03',
                fiscalCertificate: $request->file('fiscal_certificate'),
                extractedData: $extractedData
            );

            \Log::info('Tax profile created successfully', ['id' => $taxProfile->id]);

            // Limpiar datos de sesión
            session()->forget('extracted_tax_data');

            // IMPORTANTE: Siempre devolver JSON para peticiones AJAX
            if ($request->ajax() || $request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'success' => true,
                    'message' => 'Perfil fiscal creado exitosamente.',
                    'data' => [
                        'id' => $taxProfile->id,
                        'name' => $taxProfile->name,
                        'rfc' => $taxProfile->rfc,
                    ],
                    'redirect' => route('tax-profiles.index')
                ]);
            }

            // Solo redirigir normalmente si no es AJAX
            return redirect()->route('tax-profiles.index')
                ->with('success', 'Perfil fiscal creado exitosamente.');

        } catch (\Exception $e) {
            \Log::error('Error in store: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            // Devolver error en JSON para AJAX
            if ($request->ajax() || $request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'success' => false,
                    'message' => 'Error al crear el perfil fiscal: ' . $e->getMessage(),
                    'error' => config('app.debug') ? [
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ] : null
                ], 500);
            }

            return back()->withErrors(['error' => 'Error al crear el perfil fiscal: ' . $e->getMessage()]);
        }
    }

    public function update(UpdateTaxProfileRequest $request, TaxProfile $taxProfile, UpdateTaxProfileAction $action)
    {
        \Log::info('=== TAX PROFILE UPDATE ===', ['id' => $taxProfile->id]);

        try {
            // Obtener datos extraídos
            $extractedData = null;
            if ($request->has('extracted_data')) {
                $extractedData = json_decode($request->input('extracted_data'), true);
            }

            // Ejecutar el action
            $action(
                name: $request->name,
                rfc: $request->rfc,
                zipcode: $request->zipcode,
                taxRegime: $request->tax_regime,
                cfdiUse: $request->cfdi_use ?? $taxProfile->cfdi_use ?? 'G03',
                taxProfile: $taxProfile,
                fiscalCertificate: $request->hasFile('fiscal_certificate') ? $request->file('fiscal_certificate') : null,
                extractedData: $extractedData
            );

            session()->forget('extracted_tax_data');

            \Log::info('Tax profile updated successfully', ['id' => $taxProfile->id]);

            // Siempre devolver JSON para AJAX
            if ($request->ajax() || $request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'success' => true,
                    'message' => 'Perfil fiscal actualizado exitosamente.',
                    'data' => [
                        'id' => $taxProfile->id,
                        'name' => $taxProfile->name,
                        'rfc' => $taxProfile->rfc,
                    ],
                    'redirect' => route('tax-profiles.index')
                ]);
            }

            return redirect()->route('tax-profiles.index')
                ->with('success', 'Perfil fiscal actualizado exitosamente.');

        } catch (\Exception $e) {
            \Log::error('Error in update: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'tax_profile_id' => $taxProfile->id
            ]);

            if ($request->ajax() || $request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'success' => false,
                    'message' => 'Error al actualizar el perfil fiscal: ' . $e->getMessage(),
                    'error' => config('app.debug') ? [
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ] : null
                ], 500);
            }

            return back()->withErrors(['error' => 'Error al actualizar el perfil fiscal: ' . $e->getMessage()]);
        }
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
        ]);
    }

    public function destroy(DestroyTaxProfileRequest $request, TaxProfile $taxProfile, DestroyTaxProfileAction $action)
    {
        \Log::info('Deleting tax profile:', ['id' => $taxProfile->id]);

        $action($taxProfile);

        return redirect()->route('tax-profiles.index')
            ->flashMessage('Perfil fiscal eliminado exitosamente.');
    }

    public function extractData(Request $request)
    {
        \Log::info('=== EXTRACT DATA START ===');
        \Log::info('Session info:', [
            'session_id' => session()->getId(),
            'user_id' => auth()->id(),
            'user_email' => auth()->user()->email ?? null,
        ]);

        try {
            // Validar archivo
            $request->validate([
                'fiscal_certificate' => 'required|file|mimes:pdf|max:5120',
            ]);

            $file = $request->file('fiscal_certificate');

            \Log::info('Archivo recibido para extracción:', [
                'nombre' => $file->getClientOriginalName(),
                'tamaño' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'extension' => $file->getClientOriginalExtension(),
            ]);

            // Usar el servicio real para procesar el PDF
            $service = app(ConstanciaFiscalService::class);

            \Log::info('Starting PDF processing...');
            $startTime = microtime(true);

            $resultado = $service->procesarConstancia($file);

            $processingTime = microtime(true) - $startTime;
            \Log::info('PDF processing completed', [
                'success' => $resultado['success'],
                'processing_time' => round($processingTime, 2) . ' seconds'
            ]);

            if (!$resultado['success']) {
                \Log::error('Error processing PDF:', ['error' => $resultado['error']]);

                return response()->json([
                    'success' => false,
                    'message' => $resultado['error']
                ], 422);
            }

            \Log::info('Data extracted successfully:', $resultado['data']);

            return response()->json([
                'success' => true,
                'data' => $resultado['data'],
                'debug' => [
                    'processing_time' => round($processingTime, 2) . ' seconds',
                    'session_id' => session()->getId(),
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Validation error in extractData:', $e->errors());

            return response()->json([
                'success' => false,
                'message' => 'Error de validación: ' . implode(', ', $e->validator->errors()->all())
            ], 422);

        } catch (\Exception $e) {
            \Log::error('Error in extractData: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
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

            \Log::warning('Returning fallback test data due to error');

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
            if (!auth()->check()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

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
}