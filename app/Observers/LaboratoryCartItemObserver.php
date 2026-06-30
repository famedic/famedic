<?php

namespace App\Observers;

use App\Models\LaboratoryCartItem;
use App\Jobs\SendCartAddedToActiveCampaignJob;

class LaboratoryCartItemObserver
{
    public function created(LaboratoryCartItem $item): void
    {
        if (! app()->isProduction()) {
            return;
        }

        SendCartAddedToActiveCampaignJob::dispatch($item);
    }
}

