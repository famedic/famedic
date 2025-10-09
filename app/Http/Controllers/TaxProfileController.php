<?php

namespace App\Http\Controllers;

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

    public function store(StoreTaxProfileRequest $request, CreateTaxProfileAction $action)
    {
        $action(
            name: $request->name,
            rfc: $request->rfc,
            zipcode: $request->zipcode,
            taxRegime: $request->tax_regime,
            cfdiUse: $request->cfdi_use,
            fiscalCertificate: $request->file('fiscal_certificate'),
        );

        return redirect()->route('tax-profiles.index')
            ->flashMessage('Perfil fiscal creado exitosamente.');
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

    public function update(UpdateTaxProfileRequest $request, TaxProfile $taxProfile, UpdateTaxProfileAction $action)
    {
        $action(
            name: $request->name,
            rfc: $request->rfc,
            zipcode: $request->zipcode,
            taxRegime: $request->tax_regime,
            cfdiUse: $request->cfdi_use,
            taxProfile: $taxProfile,
            fiscalCertificate: $request->hasFile('fiscal_certificate') ? $request->file('fiscal_certificate') : null
        );

        return redirect()->route('tax-profiles.index')
            ->flashMessage('Perfil fiscal actualizado exitosamente.');
    }

    public function destroy(DestroyTaxProfileRequest $request, TaxProfile $taxProfile, DestroyTaxProfileAction $action)
    {
        $action($taxProfile);

        return redirect()->route('tax-profiles.index')
            ->flashMessage('Perfil fiscal eliminado exitosamente.');
    }
}
