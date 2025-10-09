<?php

namespace App\Actions\Laboratories;

use App\Models\Customer;
use App\Models\LaboratoryCartItem;

class AddItemToCartAction
{
    public function __invoke(Customer $customer, int $laboratoryTestId): LaboratoryCartItem
    {
        $laboratoryCartItem = $customer->laboratoryCartItems()
            ->whereLaboratoryTestId($laboratoryTestId)
            ->first();

        if ($laboratoryCartItem) {
            return $laboratoryCartItem;
        }

        $laboratoryCartItem = $customer->laboratoryCartItems()->save(new LaboratoryCartItem([
            'laboratory_test_id' => $laboratoryTestId,
        ]));

        return $laboratoryCartItem;
    }
}
