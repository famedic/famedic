<?php

namespace App\Actions\OnlinePharmacy;

use App\Models\OnlinePharmacyCartItem;

class UpdateItemToCartAction
{
    public function __invoke(OnlinePharmacyCartItem $onlinePharmacyCartItem, int $quantity): OnlinePharmacyCartItem | null
    {
        if ($quantity <= 0) {
            $onlinePharmacyCartItem->delete();
            return null;
        }

        $onlinePharmacyCartItem->quantity = $quantity;

        $onlinePharmacyCartItem->save();

        return $onlinePharmacyCartItem;
    }
}
