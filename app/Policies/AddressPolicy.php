<?php

namespace App\Policies;

use App\Models\Address;
use App\Models\User;

class AddressPolicy
{
    public function update(User $user, Address $address): bool
    {
        return $user->customer?->id === $address->customer_id;
    }

    public function delete(User $user, Address $address): bool
    {
        return $user->customer?->id === $address->customer_id;
    }
}
