<?php

namespace App\Actions\Laboratories;

use App\Jobs\Laboratory\SyncGdaResultPdfToStorageJob;
use App\Models\LaboratoryPurchase;
use App\Support\Laboratory\GdaResultsPdfAssessment;
use App\Support\Laboratory\GdaResultsPdfStatus;
use Illuminate\Support\Facades\Log;

/**
 * Autocuración no bloqueante en accesos de paciente al PDF de resultados.
 *
 * Evalúa frescura y, si el PDF GDA está stale, despacha el job único de sync.
 * No espera a GDA y no sobrescribe PDFs manuales.
 */
class EnsureLatestGdaResultsPdfAction
{
    /**
     * @return array{assessment: ?GdaResultsPdfAssessment, refresh_dispatched: bool}
     */
    public function execute(?LaboratoryPurchase $purchase, string $accessSource = 'patient'): array
    {
        if (! $purchase?->id) {
            return [
                'assessment' => null,
                'refresh_dispatched' => false,
            ];
        }

        $purchase->refresh();
        $assessment = GdaResultsPdfStatus::assessPurchase($purchase);
        $refreshDispatched = false;

        $context = [
            'purchase_id' => $purchase->id,
            'access_source' => $accessSource,
            'freshness_status' => $assessment->freshnessStatus,
            'pdf_kind' => $assessment->pdfKind,
            'old_path' => $purchase->results,
            'refresh_dispatched' => false,
        ];

        if ($assessment->isManual()) {
            Log::info('Patient results access: manual PDF served', $context);

            return [
                'assessment' => $assessment,
                'refresh_dispatched' => false,
            ];
        }

        if ($assessment->isGdaCurrent()) {
            Log::info('Patient results access: GDA PDF current', $context);

            return [
                'assessment' => $assessment,
                'refresh_dispatched' => false,
            ];
        }

        if ($assessment->isAutomaticOverwriteCandidate) {
            SyncGdaResultPdfToStorageJob::dispatch($purchase->id)
                ->afterResponse();

            $refreshDispatched = true;
            $context['refresh_dispatched'] = true;

            Log::info('Patient results access: stale PDF served, refresh dispatched', $context);

            return [
                'assessment' => $assessment,
                'refresh_dispatched' => true,
            ];
        }

        if (! $assessment->hasPdfInStorage && $assessment->availableAtGda) {
            Log::info('Patient results access: no stored PDF, GDA resolution required', $context);
        }

        return [
            'assessment' => $assessment,
            'refresh_dispatched' => $refreshDispatched,
        ];
    }
}
