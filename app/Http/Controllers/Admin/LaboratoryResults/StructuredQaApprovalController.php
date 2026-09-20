<?php

namespace App\Http\Controllers\Admin\LaboratoryResults;

use App\Http\Controllers\Controller;
use App\Models\LaboratoryResultReport;
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredResultApprovalException;
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredResultApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StructuredQaApprovalController extends Controller
{
    public function approve(
        Request $request,
        LaboratoryResultReport $report,
        LaboratoryStructuredResultApprovalService $approvalService,
    ): RedirectResponse {
        $this->authorizeApproval($request);

        abort_unless($report->isShadowQa(), 404);

        try {
            $result = $approvalService->approve(
                $report,
                $request->user(),
                $request->input('reason'),
            );
        } catch (LaboratoryStructuredResultApprovalException $exception) {
            return back()->withErrors([
                'approval' => $exception->getMessage(),
            ]);
        }

        $message = $result->idempotent
            ? 'El reporte ya estaba aprobado.'
            : 'Reporte aprobado para futura publicación. No se publicó al paciente.';

        return back()->with('flashMessage', [
            'type' => 'success',
            'message' => $message,
        ]);
    }

    public function reject(
        Request $request,
        LaboratoryResultReport $report,
        LaboratoryStructuredResultApprovalService $approvalService,
    ): RedirectResponse {
        $this->authorizeApproval($request);

        abort_unless($report->isShadowQa(), 404);

        $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $result = $approvalService->reject(
                $report,
                $request->user(),
                $request->input('reason'),
            );
        } catch (LaboratoryStructuredResultApprovalException $exception) {
            return back()->withErrors([
                'approval' => $exception->getMessage(),
            ]);
        }

        $message = $result->idempotent
            ? 'El reporte ya estaba rechazado.'
            : 'Aprobación de publicación rechazada.';

        return back()->with('flashMessage', [
            'type' => 'success',
            'message' => $message,
        ]);
    }

    private function authorizeApproval(Request $request): void
    {
        abort_unless(
            $request->user()?->administrator?->hasPermissionTo(
                config('laboratory-results.publication_approval.permission', 'laboratory-results.approve-publication'),
            ) ?? false,
            403,
        );
    }
}
