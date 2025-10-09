<?php

namespace App\Actions\OnlinePharmacy;

use App\Models\OnlinePharmacyCartItem;

class DeleteItemFromCartAction
{
    public function __invoke(OnlinePharmacyCartItem $onlinePharmacyCartItem): void
    {
        $onlinePharmacyCartItem->delete();
    }
}
