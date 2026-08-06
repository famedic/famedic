<?php

namespace App\Http\Controllers\Admin;

use App\Exports\ActiveCampaignOperationsExport;
use App\Http\Controllers\Controller;
use App\Services\ActiveCampaign\ActiveCampaignOperationsPlatformService;
use App\Services\ActiveCampaign\ActiveCampaignOperationsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActiveCampaignOperationsController extends Controller
{
    public function index(
        Request $request,
        ActiveCampaignOperationsService $operations,
        ActiveCampaignOperationsPlatformService $platform,
    ): Response {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $overview = $operations->overview();
        $platformDto = $platform->build($request);

        return Inertia::render('Admin/Integrations/ActiveCampaignOperations', [
            'health' => $overview['health'],
            'sync' => $overview['sync'],
            'mirror' => $overview['mirror'],
            'intelligence' => $overview['intelligence'],
            'activity' => $overview['activity'],
            'diagnostics' => $overview['diagnostics'],
            'meta' => $overview['meta'],
            'platform' => $platformDto->toArray(),
            'urls' => [
                'self' => route('admin.integrations.activecampaign'),
                'testApi' => route('admin.integrations.activecampaign.test-api'),
                'diagnostic' => route('admin.integrations.activecampaign.diagnostic'),
                'export' => route('admin.integrations.activecampaign.export'),
                'contacts' => route('admin.activecampaign.contacts'),
                'healthCenter' => route('admin.activecampaign.health'),
                'logs' => route('admin.activecampaign.logs'),
            ],
        ]);
    }

    public function testApi(Request $request, ActiveCampaignOperationsService $operations): RedirectResponse
    {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $result = $operations->runTestApi();

        return back()->with('flashMessage', [
            'type' => $result['ok'] ? 'success' : 'error',
            'message' => $result['message'],
            'diagnostic' => $result,
        ]);
    }

    public function diagnostic(Request $request, ActiveCampaignOperationsService $operations): RedirectResponse
    {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $validated = $request->validate([
            'action' => ['required', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:191'],
        ]);

        $result = $operations->runDiagnostic(
            $validated['action'],
            $validated['email'] ?? null,
        );

        return back()->with('flashMessage', [
            'type' => $result['ok'] ? 'success' : 'error',
            'message' => $result['message'],
            'diagnostic' => $result,
        ]);
    }

    public function export(
        Request $request,
        ActiveCampaignOperationsPlatformService $platform,
    ): StreamedResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse {
        $request->user()->administrator->hasPermissionTo('activecampaign.manage') || abort(403);

        $validated = $request->validate([
            'format' => ['required', 'in:csv,xlsx,pdf'],
            'dataset' => ['required', 'in:executive,laboratories,funnel,alerts,activity'],
        ]);

        $export = $platform->exportRows($request, $validated['dataset']);
        $filename = $export['filename'].'-'.now()->format('Ymd-His');

        if ($validated['format'] === 'csv') {
            return response()->streamDownload(function () use ($export) {
                $out = fopen('php://output', 'w');
                fputcsv($out, $export['headers']);
                foreach ($export['rows'] as $row) {
                    fputcsv($out, $row);
                }
                fclose($out);
            }, $filename.'.csv', [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        if ($validated['format'] === 'xlsx') {
            return Excel::download(
                new ActiveCampaignOperationsExport($export['headers'], $export['rows']),
                $filename.'.xlsx'
            );
        }

        $html = view('exports.activecampaign-operations', [
            'title' => $export['filename'],
            'headers' => $export['headers'],
            'rows' => $export['rows'],
        ])->render();

        return Pdf::loadHTML($html)->download($filename.'.pdf');
    }
}
