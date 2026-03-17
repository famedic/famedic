<?php

namespace App\Observers;

use App\Jobs\SendLaboratoryPurchaseToActiveCampaignJob;
use App\Models\LaboratoryPurchase;

class LaboratoryPurchaseObserver
{
    public function updated(LaboratoryPurchase $purchase): void
    {
        $paidJustCompleted = $purchase->isDirty('paid_at') && !is_null($purchase->paid_at);
        $statusJustCompleted = $purchase->isDirty('status') && $purchase->status === 'completed';

        if ($paidJustCompleted || $statusJustCompleted) {
            SendLaboratoryPurchaseToActiveCampaignJob::dispatch($purchase);
        }
    }
}

