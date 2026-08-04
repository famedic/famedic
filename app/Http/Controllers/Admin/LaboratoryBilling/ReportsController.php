<?php

namespace App\Http\Controllers\Admin\LaboratoryBilling;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryBilling\LaboratoryBillingDateRangeRequest;
use App\Services\LaboratoryBilling\LaboratoryBillingDateRange;
use App\Services\LaboratoryBilling\LaboratoryBillingMetricsService;
use App\Services\LaboratoryBilling\LaboratoryBillingStatusResolver;
use Inertia\Inertia;
use Inertia\Response;

class ReportsController extends Controller
{
    public function __invoke(
        LaboratoryBillingDateRangeRequest $request,
        LaboratoryBillingMetricsService $metrics,
        LaboratoryBillingStatusResolver $resolver,
    ): Response {
        $range = LaboratoryBillingDateRange::fromInput($request->input('from'), $request->input('to'));
        $compliance = $metrics->compliance($range);
        $requestCounts = $metrics->requestCounts($range);
        $taxProfileMetrics = $metrics->taxProfileMetrics($range);

        return Inertia::render('Admin/LaboratoryBilling/Reports', [
            'filters' => $range->toFilterArray(),
            'thresholdDays' => $resolver->thresholdDays(),
            'summary' => [
                'received' => $compliance['received'],
                'completed' => $compliance['completed'],
                'compliance_percent' => $compliance['percent'],
                'compliance_definition' => $compliance['definition'] ?? null,
                'average_response_hours' => $metrics->averageResponseTimeHours($range),
                'median_response_hours' => $metrics->medianResponseTimeHours($range),
                'response_time_definition' => 'Tiempo de respuesta = invoices.completed_at − invoice_requests.created_at. No cambia al reemplazar PDF/XML.',
                'overdue' => $requestCounts['overdue'],
                'new_tax_profiles' => $taxProfileMetrics['new_in_period'],
                'active_tax_profiles' => $taxProfileMetrics['active'],
                'unused_tax_profiles' => $taxProfileMetrics['unused'],
            ],
            'compliance' => $compliance,
            'requestsVsInvoices' => $metrics->requestsVsInvoicesSeries($range),
            'newTaxProfiles' => $metrics->newTaxProfilesSeries($range),
            'profilesByTipoPersona' => $metrics->profilesByTipoPersona(),
            'profilesByStatus' => $metrics->profilesByStatus(),
            'onTimeVsLate' => $metrics->onTimeVsLate($range),
            'topOverdue' => $metrics->topOverdue($range, 10),
            'unusedOldest' => $metrics->unusedProfilesOldest(10),
            'topPatients' => $metrics->topPatientsByRequests($range, 10),
        ]);
    }
}
