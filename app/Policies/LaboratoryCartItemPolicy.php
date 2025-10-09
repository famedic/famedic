<?php

namespace App\Policies;

use App\Models\LaboratoryCartItem;
use App\Models\User;

class LaboratoryCartItemPolicy
{
    public function delete(User $user, LaboratoryCartItem $laboratoryCartItem): bool
    {
        return $user->customer?->id === $laboratoryCartItem->customer_id;
    }
}
