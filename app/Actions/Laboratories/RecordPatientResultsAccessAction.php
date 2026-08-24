<?php

namespace App\Actions\Laboratories;

use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Support\Laboratory\GdaResultsPdfStatus;

/**
 * Registra que el paciente accedió a la versión actual de resultados.
 *
 * No equivale a un fetch/sync desde GDA. Solo marca read_at cuando el PDF
 * servido está gda_current o es un PDF manual.
 */
class RecordPatientResultsAccessAction
{
    public function execute(LaboratoryPurchase $purchase): bool
    {
        $purchase->refresh();

        if (empty($purchase->results)) {
            return false;
        }

        $assessment = GdaResultsPdfStatus::assessPurchase($purchase);

        if ($assessment->isGdaStale() || $assessment->isAutomaticOverwriteCandidate) {
            return false;
        }

        if (! $assessment->isGdaCurrent() && ! $assessment->isManual()) {
            return false;
        }

        LaboratoryNotification::markResultsCoveredByServedPdfAsRead($purchase, $assessment);

        return true;
    }
}
