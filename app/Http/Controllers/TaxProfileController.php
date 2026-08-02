<?php

namespace App\Http\Controllers;

use App\Services\ConstanciaFiscalService;
use App\Actions\TaxProfiles\CreateTaxProfileAction;
use App\Actions\TaxProfiles\DestroyTaxProfileAction;
use App\Actions\TaxProfiles\SetDefaultTaxProfileAction;
use App\Actions\TaxProfiles\UpdateTaxProfileAction;
use App\Http\Requests\TaxProfiles\DestroyTaxProfileRequest;
use App\Http\Requests\TaxProfiles\EditTaxProfileRequest;
use App\Http\Requests\TaxProfiles\SetDefaultTaxProfileRequest;
use App\Http\Requests\TaxProfiles\StoreTaxProfileRequest;
use App\Http\Requests\TaxProfiles\UpdateTaxProfileRequest;
use App\Models\Invoice;
use App\Models\LaboratoryPurchase;
use App\Models\OnlinePharmacyPurchase;
use App\Models\TaxProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class TaxProfileController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('TaxProfiles', [
            'taxProfiles' => $this->patientTaxProfiles($request),
            'invoices' => $this->patientInvoicesPaginator($request),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('TaxProfiles', [
            'taxProfiles' => $this->patientTaxProfiles($request),
            'invoices' => $this->patientInvoicesPaginator($request),
            'taxRegimes' => config('taxregimes.regimes'),
        ]);
    }


    public function store(StoreTaxProfileRequest $request, CreateTaxProfileAction $action)
    {
        try {
            $extractedData = null;
            if ($request->has('extracted_data')) {
                $extractedData = json_decode($request->input('extracted_data'), true);
            }

            $taxProfile = $action(
                name: $request->name,
                rfc: $request->rfc,
                zipcode: $request->zipcode,
                taxRegime: $request->tax_regime,
                cfdiUse: $request->cfdi_use ?? 'G03',
                fiscalCertificate: $request->file('fiscal_certificate'),
                extractedData: $extractedData
            );

            Log::info('Perfil fiscal creado', [
                'operation' => 'tax_profile_store',
                'user_id' => $request->user()->id,
                'customer_id' => $request->user()->customer->id,
                'tax_profile_id' => $taxProfile->id,
                'result' => 'success',
            ]);

            session()->forget('extracted_tax_data');

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

            return redirect()->route('tax-profiles.index')
                ->with('success', 'Perfil fiscal creado exitosamente.');
        } catch (\Exception $e) {
            Log::error('Error al crear perfil fiscal', [
                'operation' => 'tax_profile_store',
                'user_id' => $request->user()->id,
                'customer_id' => $request->user()->customer->id,
                'result' => 'exception',
                'exception_class' => $e::class,
            ]);

            if ($request->ajax() || $request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'success' => false,
                    'message' => 'Error al crear el perfil fiscal.',
                ], 500);
            }

            return back()->withErrors(['error' => 'Error al crear el perfil fiscal.']);
        }
    }

    public function update(UpdateTaxProfileRequest $request, TaxProfile $taxProfile, UpdateTaxProfileAction $action)
    {
        try {
            $extractedData = null;
            if ($request->has('extracted_data')) {
                $extractedData = json_decode($request->input('extracted_data'), true);
            }

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

            Log::info('Perfil fiscal actualizado', [
                'operation' => 'tax_profile_update',
                'user_id' => $request->user()->id,
                'customer_id' => $request->user()->customer->id,
                'tax_profile_id' => $taxProfile->id,
                'result' => 'success',
            ]);

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
            Log::error('Error al actualizar perfil fiscal', [
                'operation' => 'tax_profile_update',
                'user_id' => $request->user()->id,
                'customer_id' => $request->user()->customer->id,
                'tax_profile_id' => $taxProfile->id,
                'result' => 'exception',
                'exception_class' => $e::class,
            ]);

            if ($request->ajax() || $request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'success' => false,
                    'message' => 'Error al actualizar el perfil fiscal.',
                ], 500);
            }

            return back()->withErrors(['error' => 'Error al actualizar el perfil fiscal.']);
        }
    }

    public function edit(EditTaxProfileRequest $request, TaxProfile $taxProfile)
    {
        return Inertia::render('TaxProfiles', [
            'taxProfiles' => $this->patientTaxProfiles($request),
            'invoices' => $this->patientInvoicesPaginator($request),
            'taxProfile' => $taxProfile->presentForPatient(),
            'taxRegimes' => config('taxregimes.regimes'),
        ]);
    }

    public function destroy(DestroyTaxProfileRequest $request, TaxProfile $taxProfile, DestroyTaxProfileAction $action)
    {
        Log::info('Desactivando perfil fiscal', [
            'operation' => 'tax_profile_destroy',
            'user_id' => $request->user()->id,
            'customer_id' => $request->user()->customer->id,
            'tax_profile_id' => $taxProfile->id,
        ]);

        $action($taxProfile);

        return redirect()->route('tax-profiles.index')
            ->flashMessage('Perfil fiscal desactivado exitosamente.');
    }

    public function setDefault(
        SetDefaultTaxProfileRequest $request,
        TaxProfile $taxProfile,
        SetDefaultTaxProfileAction $action
    ) {
        Log::info('Estableciendo perfil fiscal predeterminado', [
            'operation' => 'tax_profile_set_default',
            'user_id' => $request->user()->id,
            'customer_id' => $request->user()->customer->id,
            'tax_profile_id' => $taxProfile->id,
        ]);

        $action($taxProfile);

        if ($request->ajax() || $request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
            return response()->json([
                'success' => true,
                'message' => 'Perfil fiscal establecido como predeterminado.',
                'redirect' => route('tax-profiles.index'),
            ]);
        }

        return redirect()->route('tax-profiles.index')
            ->flashMessage('Perfil fiscal establecido como predeterminado.');
    }

    public function extractData(Request $request)
    {
        try {
            $request->validate([
                'fiscal_certificate' => 'required|file|mimes:pdf|max:5120',
            ]);

            $file = $request->file('fiscal_certificate');

            Log::info('Solicitud de extracción de constancia recibida', [
                'operation' => 'constancia_extract_request',
                'user_id' => auth()->id(),
                'customer_id' => auth()->user()?->customer?->id,
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
            ]);

            $service = app(ConstanciaFiscalService::class);
            $startTime = microtime(true);
            $resultado = $service->procesarConstancia($file);
            $processingTime = microtime(true) - $startTime;

            if (! ($resultado['success'] ?? false)) {
                Log::warning('Extracción de constancia fallida', [
                    'operation' => 'constancia_extract_request',
                    'user_id' => auth()->id(),
                    'customer_id' => auth()->user()?->customer?->id,
                    'result' => 'failure',
                    'duration_ms' => (int) round($processingTime * 1000),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $resultado['error']
                        ?? 'No pudimos extraer los datos de la constancia. Puedes capturarlos manualmente.',
                    'data' => null,
                ], 422);
            }

            Log::info('Extracción de constancia respondida al cliente', [
                'operation' => 'constancia_extract_request',
                'user_id' => auth()->id(),
                'customer_id' => auth()->user()?->customer?->id,
                'result' => 'success',
                'duration_ms' => (int) round($processingTime * 1000),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Datos extraídos correctamente. Revisa y confirma antes de guardar.',
                'data' => $resultado['data'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'El archivo de constancia no es válido. Debe ser un PDF de máximo 5 MB.',
                'data' => null,
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Error inesperado en extractData', [
                'operation' => 'constancia_extract_request',
                'user_id' => auth()->id(),
                'customer_id' => auth()->user()?->customer?->id,
                'result' => 'exception',
                'exception_class' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No pudimos extraer los datos de la constancia. Puedes capturarlos manualmente.',
                'data' => null,
            ], 422);
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
                'message' => 'Error al verificar el servicio de extracción.',
            ], 500);
        }
    }

    private function patientTaxProfiles(Request $request)
    {
        return $request->user()->customer->taxProfiles
            ->map->presentForPatient()
            ->values();
    }

    private function patientInvoicesPaginator(Request $request)
    {
        return Invoice::whereHasMorph(
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
        ])->paginate()->through(fn (Invoice $invoice) => $invoice->presentForPatient());
    }
}