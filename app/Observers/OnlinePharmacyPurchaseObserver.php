<?php

namespace App\Observers;

use App\Jobs\SendOnlinePharmacyPurchaseToActiveCampaignJob;
use App\Models\OnlinePharmacyPurchase;

class OnlinePharmacyPurchaseObserver
{
    public function updated(OnlinePharmacyPurchase $purchase): void
    {
        // Mantener misma lógica de negocio que en laboratorio:
        $paidJustCompleted = $purchase->isDirty('paid_at') && !is_null($purchase->paid_at);
        $statusJustCompleted = $purchase->isDirty('status') && $purchase->status === 'completed';

        if ($paidJustCompleted || $statusJustCompleted) {
            SendOnlinePharmacyPurchaseToActiveCampaignJob::dispatch($purchase);
        }
    }
}

