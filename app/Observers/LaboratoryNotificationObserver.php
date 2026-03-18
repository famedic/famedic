<?php

namespace App\Observers;

use App\Models\LaboratoryNotification;
use App\Jobs\SendResultsAvailableToActiveCampaignJob;

class LaboratoryNotificationObserver
{
    public function updated(LaboratoryNotification $notification): void
    {
        // Despachar solo cuando el correo realmente se marcó como enviado.
        $isResultsType = $notification->isResultsType();
        $emailJustSent = $notification->isDirty('email_sent_at') && $notification->email_sent_at !== null;

        if ($isResultsType && $emailJustSent) {
            SendResultsAvailableToActiveCampaignJob::dispatch($notification);
        }
    }
}

