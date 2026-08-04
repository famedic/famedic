<?php

namespace App\Http\Controllers;

use App\Actions\TaxProfiles\CreateTaxProfileAction;
use App\Actions\TaxProfiles\DestroyTaxProfileAction;
use App\Actions\TaxProfiles\ExtractTaxProfileFromConstanciaAction;
use App\Actions\TaxProfiles\SetDefaultTaxProfileAction;
use App\Actions\TaxProfiles\UpdateTaxProfileAction;
use App\Exceptions\TaxProfiles\ConstanciaExtractionException;
use App\Http\Requests\TaxProfiles\DestroyTaxProfileRequest;
use App\Http\Requests\TaxProfiles\EditTaxProfileRequest;
use App\Http\Requests\TaxProfiles\ExtractTaxProfileDataRequest;
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
            $extractedData = $request->decodedExtractedData();

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

            // Inertia must receive a redirect; the TaxProfileForm fetch API expects JSON.
            if ($request->header('X-Inertia')) {
                return redirect()->route('tax-profiles.index')
                    ->flashMessage('Perfil fiscal creado exitosamente.');
            }

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
                ->flashMessage('Perfil fiscal creado exitosamente.');
        } catch (\InvalidArgumentException $e) {
            Log::warning('Creación de perfil fiscal rechazada', [
                'operation' => 'tax_profile_store',
                'user_id' => $request->user()->id,
                'customer_id' => $request->user()->customer->id,
                'result' => 'rejected',
                'exception_class' => $e::class,
            ]);

            if ($request->header('X-Inertia')) {
                return back()->withErrors(['error' => $e->getMessage()]);
            }

            if ($request->ajax() || $request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('Error al crear perfil fiscal', [
                'operation' => 'tax_profile_store',
                'user_id' => $request->user()->id,
                'customer_id' => $request->user()->customer->id,
                'result' => 'exception',
                'exception_class' => $e::class,
            ]);

            if ($request->header('X-Inertia')) {
                return back()->withErrors(['error' => 'Error al crear el perfil fiscal.']);
            }

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
            $extractedData = $request->decodedExtractedData();

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

            // Inertia must receive a redirect; the TaxProfileForm fetch API expects JSON.
            if ($request->header('X-Inertia')) {
                return redirect()->route('tax-profiles.index')
                    ->flashMessage('Perfil fiscal actualizado exitosamente.');
            }

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
                ->flashMessage('Perfil fiscal actualizado exitosamente.');
        } catch (\InvalidArgumentException $e) {
            Log::warning('Actualización de perfil fiscal rechazada', [
                'operation' => 'tax_profile_update',
                'user_id' => $request->user()->id,
                'customer_id' => $request->user()->customer->id,
                'tax_profile_id' => $taxProfile->id,
                'result' => 'rejected',
                'exception_class' => $e::class,
            ]);

            if ($request->header('X-Inertia')) {
                return back()->withErrors(['error' => $e->getMessage()]);
            }

            if ($request->ajax() || $request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('Error al actualizar perfil fiscal', [
                'operation' => 'tax_profile_update',
                'user_id' => $request->user()->id,
                'customer_id' => $request->user()->customer->id,
                'tax_profile_id' => $taxProfile->id,
                'result' => 'exception',
                'exception_class' => $e::class,
            ]);

            if ($request->header('X-Inertia')) {
                return back()->withErrors(['error' => 'Error al actualizar el perfil fiscal.']);
            }

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

        // Always return an Inertia-compatible redirect. This endpoint is only
        // invoked via Inertia (useForm().patch); plain JSON breaks the client.
        return redirect()->route('tax-profiles.index')
            ->flashMessage('Perfil fiscal establecido como predeterminado.');
    }

    public function extractData(
        ExtractTaxProfileDataRequest $request,
        ExtractTaxProfileFromConstanciaAction $action
    ) {
        $file = $request->file('fiscal_certificate');

        Log::info('Solicitud de extracción de constancia recibida', [
            'operation' => 'constancia_extract_request',
            'user_id' => $request->user()->id,
            'customer_id' => $request->user()->customer?->id,
            'mime_type' => $file?->getMimeType(),
            'size_bytes' => $file?->getSize(),
        ]);

        try {
            $result = $action($file);

            return response()->json([
                'success' => true,
                'message' => 'Datos extraídos correctamente. Revisa y confirma antes de guardar.',
                'data' => $result->toHttpData(),
            ]);
        } catch (ConstanciaExtractionException $e) {
            return response()->json([
                'success' => false,
                'code' => $e->errorCode,
                'message' => $e->publicMessage(),
                'data' => null,
            ], $e->status);
        } catch (\Throwable $e) {
            Log::error('Error inesperado en extractData', [
                'operation' => 'constancia_extract_request',
                'user_id' => $request->user()->id,
                'customer_id' => $request->user()->customer?->id,
                'result' => 'exception',
                'exception_class' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'code' => ConstanciaExtractionException::EXTRACTION_FAILED,
                'message' => 'No pudimos extraer los datos de la constancia. Puedes capturarlos manualmente.',
                'data' => null,
            ], 422);
        }
    }

    private function patientTaxProfiles(Request $request)
    {
        return TaxProfile::presentCollectionForPatient($request->user()->customer);
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