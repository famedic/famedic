<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AutomationOperations\AutomationOperationsAnalyticsService;
use App\Services\AutomationOperations\AutomationOperationsDiagnosticsService;
use App\Services\AutomationOperations\AutomationQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AutomationOperationsController extends Controller
{
    public function index(
        Request $request,
        AutomationOperationsAnalyticsService $analytics,
        AutomationOperationsDiagnosticsService $diagnostics,
    ): Response {
        $request->user()->administrator->hasPermissionTo('automation.manage') || abort(403);

        $dashboard = $analytics->build();
        $tab = (string) $request->input('tab', 'dashboard');

        return Inertia::render('Admin/Integrations/AutomationOperations', [
            'tab' => $tab,
            'health' => $dashboard['health'],
            'kpis' => $dashboard['kpis'],
            'drivers' => $dashboard['drivers'],
            'timeline' => $dashboard['timeline'],
            'performance' => $dashboard['performance'],
            'queue' => $dashboard['queue'],
            'architecture' => $dashboard['architecture'],
            'roadmap' => $dashboard['roadmap'],
            'paymentProxy' => $dashboard['payment_proxy'],
            'acProxy' => $dashboard['ac_proxy'],
            'diagnosticsCatalog' => $diagnostics->catalog(),
            'meta' => $dashboard['meta'],
            'diagnosticUrl' => route('admin.automation.diagnostic'),
            'queueActionUrl' => route('admin.automation.queue.action'),
        ]);
    }

    public function diagnostic(
        Request $request,
        AutomationOperationsDiagnosticsService $diagnostics,
    ): JsonResponse {
        $request->user()->administrator->hasPermissionTo('automation.manage') || abort(403);

        $validated = $request->validate([
            'key' => ['required', 'string', 'in:driver,activecampaign,email,whatsapp,dispatcher'],
        ]);

        $result = $diagnostics->run($validated['key']);

        return response()->json([
            'ok' => $result->status === 'success',
            'result' => $result->toArray(),
        ]);
    }

    public function queueAction(
        Request $request,
        AutomationQueueService $queue,
    ): JsonResponse {
        $request->user()->administrator->hasPermissionTo('automation.manage') || abort(403);

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:retry,requeue,discard'],
            'run_id' => ['required_if:action,retry', 'nullable', 'integer'],
            'dead_letter_id' => ['required_if:action,requeue,discard', 'nullable', 'integer'],
        ]);

        try {
            $payload = match ($validated['action']) {
                'retry' => [
                    'run' => $queue->retryManual((int) $validated['run_id'])->only([
                        'id', 'automation_uuid', 'status', 'attempt',
                    ]),
                ],
                'requeue' => [
                    'run' => $queue->requeueDeadLetter((int) $validated['dead_letter_id'])->only([
                        'id', 'automation_uuid', 'status', 'attempt',
                    ]),
                ],
                'discard' => [
                    'dead_letter' => $queue->discardDeadLetter(
                        (int) $validated['dead_letter_id'],
                        $request->user()->id,
                    )->only(['id', 'automation_uuid', 'status']),
                ],
            };

            return response()->json(['ok' => true, ...$payload]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
