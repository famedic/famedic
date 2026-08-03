<?php

namespace App\Http\Controllers\Admin\LaboratoryBilling;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryBilling\IndexLaboratoryBillingRequestsRequest;
use App\Services\LaboratoryBilling\LaboratoryBillingDateRange;
use App\Services\LaboratoryBilling\LaboratoryBillingRequestsQuery;
use App\Services\LaboratoryBilling\LaboratoryBillingStatusResolver;
use Inertia\Inertia;
use Inertia\Response;

class RequestsController extends Controller
{
    public function __invoke(
        IndexLaboratoryBillingRequestsRequest $request,
        LaboratoryBillingRequestsQuery $query,
        LaboratoryBillingStatusResolver $resolver,
    ): Response {
        $range = LaboratoryBillingDateRange::fromInput($request->input('from'), $request->input('to'));
        $filters = collect($request->only([
            'search',
            'status',
            'overdue',
            'document',
            'tax_profile_id',
            'customer_id',
            'brand',
            'from',
            'to',
        ]))->filter(fn ($value) => $value !== null && $value !== '')->all();

        $filters = array_merge($filters, $range->toFilterArray());

        return Inertia::render('Admin/LaboratoryBilling/Requests', [
            'requests' => $query->paginate($filters, $range),
            'filters' => $filters,
            'statusCounts' => $query->statusCounts($filters, $range),
            'brandOptions' => $query->brandOptions(),
            'thresholdDays' => $resolver->thresholdDays(),
        ]);
    }
}
