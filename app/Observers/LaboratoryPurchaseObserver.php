<?php

namespace App\Observers;

use App\Models\LaboratoryPurchase;

class LaboratoryPurchaseObserver
{
    public function updated(LaboratoryPurchase $purchase): void
    {
        // ActiveCampaign laboratory purchase sync is now owned by the order automation outbox.
        // Later status changes, including results completion, must not be interpreted as a new purchase.
    }
}
