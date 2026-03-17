<?php

namespace App\Observers;

use App\Models\LaboratoryCartItem;
use App\Jobs\SendCartAddedToActiveCampaignJob;

class LaboratoryCartItemObserver
{
    public function created(LaboratoryCartItem $item): void
    {
        // Producto agregado al carrito
        SendCartAddedToActiveCampaignJob::dispatch($item);
    }
}

