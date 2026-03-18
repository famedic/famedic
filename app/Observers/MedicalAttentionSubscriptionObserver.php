<?php

namespace App\Observers;

use App\Models\MedicalAttentionSubscription;
use App\Jobs\SendMembershipActivatedToActiveCampaignJob;
use App\Jobs\SendMembershipEndedToActiveCampaignJob;

class MedicalAttentionSubscriptionObserver
{
    public function created(MedicalAttentionSubscription $subscription): void
    {
        // Al crear una suscripción asumimos que se activó la membresía
        SendMembershipActivatedToActiveCampaignJob::dispatch($subscription);
    }

    public function updated(MedicalAttentionSubscription $subscription): void
    {
        // Cuando la fecha de fin cambia y ya expiró, marcamos como terminada
        if ($subscription->isDirty('end_date') && $subscription->end_date < now()) {
            SendMembershipEndedToActiveCampaignJob::dispatch($subscription);
        }
    }
}

