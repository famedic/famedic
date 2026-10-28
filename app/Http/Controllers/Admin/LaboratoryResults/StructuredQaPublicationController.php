<?php

namespace App\Http\Controllers\Admin\LaboratoryResults;

use App\Http\Controllers\Controller;
use App\Models\LaboratoryResultReport;
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredResultPublicationException;
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredResultPublicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StructuredQaPublicationController extends Controller
{
    public function publish(
        Request $request,
        LaboratoryResultReport $report,
        LaboratoryStructuredResultPublicationService $publicationService,
    ): RedirectResponse {
        $this->authorizePublish($request);

        try {
            $result = $publicationService->publish($report, $request->user(), dryRun: false);
        } catch (LaboratoryStructuredResultPublicationException $exception) {
            return back()->withErrors([
                'publication' => $exception->getMessage(),
            ]);
        }

        $message = $result->idempotent
            ? 'El reporte ya estaba publicado.'
            : 'Resultado estructurado publicado. Disponible para el paciente vía activePublished().';

        return back()->with('flashMessage', [
            'type' => 'success',
            'message' => $message,
        ]);
    }

    private function authorizePublish(Request $request): void
    {
        abort_unless(
            $request->user()?->administrator?->hasPermissionTo(
                config('laboratory-results.structured_publication.permission', 'laboratory-results.publish'),
            ) ?? false,
            403,
        );
    }
}
