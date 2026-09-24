<?php

namespace App\Http\Controllers\Admin\LaboratoryBilling;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryBilling\IndexLaboratoryBillingRequestsRequest;
use App\Services\LaboratoryBilling\LaboratoryBillingAccess;
use App\Services\LaboratoryBilling\LaboratoryBillingAwaitingSampleQuery;
use App\Services\LaboratoryBilling\LaboratoryBillingDateRange;
use App\Services\LaboratoryBilling\LaboratoryBillingNavCounts;
use App\Services\LaboratoryBilling\LaboratoryBillingRequestsQuery;
use Inertia\Inertia;
use Inertia\Response;

class AwaitingSampleController extends Controller
{
    public function __invoke(
        IndexLaboratoryBillingRequestsRequest $request,
        LaboratoryBillingAwaitingSampleQuery $query,
        LaboratoryBillingRequestsQuery $requestsQuery,
        LaboratoryBillingNavCounts $navCounts,
        LaboratoryBillingAccess $access,
    ): Response {
        $range = LaboratoryBillingDateRange::fromInput($request->input('from'), $request->input('to'));
        $filters = collect($request->only([
            'search',
            'brand',
            'from',
            'to',
        ]))->filter(fn ($value) => $value !== null && $value !== '')->all();

        $filters = array_merge($filters, $range->toFilterArray());

        return Inertia::render('Admin/LaboratoryBilling/AwaitingSample', [
            'requests' => $query->paginate($filters, $range),
            'filters' => $filters,
            'brandOptions' => $requestsQuery->brandOptions(),
            'navCounts' => $navCounts->toArray(),
            'canManageAutomaticReports' => $access->allowsReports($request->user()),
        ]);
    }
}
