<?php

namespace App\Http\Controllers\Admin\LaboratoryBilling;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryBilling\LaboratoryBillingDateRangeRequest;
use App\Services\LaboratoryBilling\LaboratoryBillingDateRange;
use App\Services\LaboratoryBilling\LaboratoryBillingMetricsService;
use App\Services\LaboratoryBilling\LaboratoryBillingStatusResolver;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(
        LaboratoryBillingDateRangeRequest $request,
        LaboratoryBillingMetricsService $metrics,
        LaboratoryBillingStatusResolver $resolver,
    ): Response {
        $range = LaboratoryBillingDateRange::fromInput($request->input('from'), $request->input('to'));

        return Inertia::render('Admin/LaboratoryBilling/Dashboard', [
            'filters' => $range->toFilterArray(),
            'thresholdDays' => $resolver->thresholdDays(),
            'requestMetrics' => $metrics->requestCountsWithDelta($range),
            'taxProfileMetrics' => $metrics->taxProfileMetrics($range),
            'compliance' => $metrics->compliance($range),
            'requestsVsInvoices' => $metrics->requestsVsInvoicesSeries($range),
            'newTaxProfiles' => $metrics->newTaxProfilesSeries($range),
            'topOverdue' => $metrics->topOverdue($range),
            'recentActivity' => $metrics->recentActivity($range),
        ]);
    }
}
