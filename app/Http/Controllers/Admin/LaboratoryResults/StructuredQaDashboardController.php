<?php

namespace App\Http\Controllers\Admin\LaboratoryResults;

use App\Http\Controllers\Controller;
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredQaDashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StructuredQaDashboardController extends Controller
{
    public function __invoke(Request $request, LaboratoryStructuredQaDashboardService $dashboard): Response
    {
        abort_unless(
            $request->user()?->administrator?->hasPermissionTo('laboratory-notifications.monitor') ?? false,
            403,
        );

        $filters = $request->only([
            'analyte_code',
            'reference_status',
            'structured_status',
            'promotion_status',
            'approval_status',
            'extraction_method',
            'version_id',
            'confidence_min',
            'date_from',
            'date_to',
        ]);

        $detailObservationId = $request->integer('observation_id') ?: null;
        $payload = $dashboard->build($filters, $detailObservationId);

        return Inertia::render('Admin/LaboratoryResults/StructuredQaDashboard', [
            'filters' => $filters,
            'filterOptions' => $payload['filterOptions'],
            'summary' => $payload['summary'],
            'rows' => $payload['rows'],
            'detail' => $payload['detail'],
            'meta' => $payload['meta'],
        ]);
    }
}
