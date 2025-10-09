<?php

namespace App\Actions\OnlinePharmacy;

use App\Models\Customer;
use App\Models\OnlinePharmacyCartItem;

class AddItemToCartAction
{
    public function __invoke(Customer $customer, int $vitauProductId): OnlinePharmacyCartItem
    {
        $onlinePharmacyCartItem = $customer->onlinePharmacyCartItems()->whereVitauProductId($vitauProductId)->first();

        if ($onlinePharmacyCartItem) {
            return $onlinePharmacyCartItem;
        }

        $onlinePharmacyCartItem = $customer->onlinePharmacyCartItems()->save(new OnlinePharmacyCartItem([
            'vitau_product_id' => $vitauProductId,
        ]));

        return $onlinePharmacyCartItem;
    }
}
