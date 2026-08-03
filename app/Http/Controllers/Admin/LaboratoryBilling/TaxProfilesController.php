<?php

namespace App\Http\Controllers\Admin\LaboratoryBilling;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryBilling\IndexLaboratoryBillingTaxProfilesRequest;
use App\Http\Requests\Admin\LaboratoryBilling\LaboratoryBillingDateRangeRequest;
use App\Models\TaxProfile;
use App\Services\LaboratoryBilling\LaboratoryBillingAccess;
use App\Services\LaboratoryBilling\LaboratoryBillingDateRange;
use App\Services\LaboratoryBilling\LaboratoryBillingTaxProfilesQuery;
use Inertia\Inertia;
use Inertia\Response;

class TaxProfilesController extends Controller
{
    public function index(
        IndexLaboratoryBillingTaxProfilesRequest $request,
        LaboratoryBillingTaxProfilesQuery $query,
    ): Response {
        $range = LaboratoryBillingDateRange::fromInput($request->input('from'), $request->input('to'));
        $filters = collect($request->only([
            'search',
            'status',
            'usage',
            'is_default',
            'tipo_persona',
            'include_deleted',
            'created_in_range',
            'from',
            'to',
        ]))->filter(fn ($value) => $value !== null && $value !== '')->all();

        $filters = array_merge($filters, $range->toFilterArray());

        return Inertia::render('Admin/LaboratoryBilling/TaxProfiles', [
            'taxProfiles' => $query->paginate($filters, $range),
            'filters' => $filters,
            'metrics' => $query->metrics($range),
        ]);
    }

    public function show(
        int $tax_profile,
        LaboratoryBillingDateRangeRequest $request,
        LaboratoryBillingTaxProfilesQuery $query,
        LaboratoryBillingAccess $access,
    ): Response {
        $access->authorize($request->user());

        $taxProfile = TaxProfile::withTrashed()->findOrFail($tax_profile);

        return Inertia::render('Admin/LaboratoryBilling/TaxProfileShow', [
            'taxProfile' => $query->findForShow($taxProfile),
            'filters' => LaboratoryBillingDateRange::fromInput(
                $request->input('from'),
                $request->input('to')
            )->toFilterArray(),
        ]);
    }
}
