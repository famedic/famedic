<?php

namespace App\Observers;

use App\Models\LaboratoryNotification;

class LaboratoryNotificationObserver
{
    public function updated(LaboratoryNotification $notification): void
    {
        // Results ActiveCampaign sync is emitted only by the GDA results completion gate.
        // Keeping this observer inert prevents duplicate jobs when email_sent_at changes.
    }
}
