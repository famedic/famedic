<?php

namespace App\Observers;

use App\Models\LaboratoryNotification;
use App\Jobs\SendResultsAvailableToActiveCampaignJob;

class LaboratoryNotificationObserver
{
    public function updated(LaboratoryNotification $notification): void
    {
        // Resultados disponibles cuando es tipo results y se establece results_received_at
        $isResultsType = $notification->isResultsType();
        $resultsJustReceived = $notification->isDirty('results_received_at') && $notification->results_received_at !== null;

        if ($isResultsType && $resultsJustReceived) {
            SendResultsAvailableToActiveCampaignJob::dispatch($notification);
        }
    }
}

