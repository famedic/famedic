<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Murguia\MurguiaExportService;
use App\Services\Murguia\MurguiaReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MurguiaReportController extends Controller
{
    public function index(Request $request, MurguiaReportService $reportService): Response
    {
        $filters = $reportService->resolveFilters($request->all());

        return Inertia::render('Admin/Murguia/Reports', [
            'filters' => array_merge(['preset' => $request->input('preset', '')], $filters),
            'presets' => array_keys(MurguiaReportService::PRESETS),
            'rows' => $reportService->paginate($filters),
        ]);
    }

    public function export(
        Request $request,
        MurguiaReportService $reportService,
        MurguiaExportService $exportService
    ): StreamedResponse|BinaryFileResponse {
        $validated = $request->validate([
            'format' => ['required', 'in:csv,xlsx'],
        ]);

        $filters = $reportService->resolveFilters($request->all());

        return $exportService->export($filters, $validated['format']);
    }
}
