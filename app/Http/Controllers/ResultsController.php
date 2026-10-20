<?php

namespace App\Http\Controllers;

use App\Http\Requests\Laboratories\ShowLaboratoryResultsRequest;
use App\Actions\Laboratories\EnsureLatestGdaResultsPdfAction;
use App\Actions\Laboratories\RecoverLaboratoryResultPdfForPatientAction;
use App\Actions\Laboratories\RecordPatientResultsAccessAction;
use App\Exceptions\LaboratoryResultsRecoveryInProgressException;
use App\Exceptions\LaboratoryResultsRecoveryUnavailableException;
use App\Models\LaboratoryPurchase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ResultsController extends Controller
{
    public function __invoke(
        ShowLaboratoryResultsRequest $request,
        LaboratoryPurchase $laboratoryPurchase,
        RecoverLaboratoryResultPdfForPatientAction $recoverLaboratoryResultPdfForPatientAction,
    )
    {
        $this->authorize('view', $laboratoryPurchase);

        if ((bool) config('laboratory-results.recovery.gda_first', false)) {
            try {
                $recovery = $recoverLaboratoryResultPdfForPatientAction->execute(
                    $laboratoryPurchase,
                    (string) $request->headers->get('X-Request-Id') ?: null
                );

                $laboratoryPurchase->refresh();
            } catch (LaboratoryResultsRecoveryInProgressException) {
                return $this->controlledError(
                    $request,
                    'Ya estamos obteniendo tus resultados. Intenta nuevamente en unos segundos.',
                    409
                );
            } catch (LaboratoryResultsRecoveryUnavailableException $exception) {
                Log::warning('laboratory_results_recovery.patient_error', [
                    'purchase_id' => $laboratoryPurchase->id,
                    'error_code' => $exception->errorCode,
                ]);

                $message = $exception->errorCode === 'result_incomplete'
                    ? 'Tus resultados aún se están procesando. Intenta nuevamente más tarde.'
                    : 'No fue posible obtener tus resultados en este momento. Intenta nuevamente en unos minutos.';

                return $this->controlledError($request, $message, 503);
            }
        } else {
            app(EnsureLatestGdaResultsPdfAction::class)->execute(
                $laboratoryPurchase,
                'patient_results'
            );

            $laboratoryPurchase->refresh();
        }

        if (empty($laboratoryPurchase->results) || ! Storage::exists($laboratoryPurchase->results)) {
            return $this->controlledError($request, 'Resultado no disponible', 404);
        }

        $url = Storage::temporaryUrl(
            $laboratoryPurchase->results,
            now()->addMinutes(5)
        );

        app(RecordPatientResultsAccessAction::class)->execute($laboratoryPurchase);

        if ((bool) config('laboratory-results.recovery.gda_first', false)) {
            Log::info('laboratory_results_recovery.served', [
                'purchase_id' => $laboratoryPurchase->id,
                'correlation_id' => $recovery['correlation_id'] ?? null,
                'recovered' => $recovery['recovered'] ?? false,
                'fallback' => $recovery['fallback'] ?? false,
            ]);
        }

        if ($request->expectsJson()) {
            return response()->json(['url' => $url]);
        }

        return Inertia::location($url);
    }

    private function controlledError(ShowLaboratoryResultsRequest $request, string $message, int $status)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        abort($status, $message);
    }
}
