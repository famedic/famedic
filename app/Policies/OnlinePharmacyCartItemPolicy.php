<?php

namespace App\Policies;

use App\Models\OnlinePharmacyCartItem;
use App\Models\User;

class OnlinePharmacyCartItemPolicy
{
    public function update(User $user, OnlinePharmacyCartItem $onlinePharmacyCartItem): bool
    {
        return $user->customer?->id === $onlinePharmacyCartItem->customer_id;
    }

    public function delete(User $user, OnlinePharmacyCartItem $onlinePharmacyCartItem): bool
    {
        return $user->customer?->id === $onlinePharmacyCartItem->customer_id;
    }
}
