<?php

namespace App\Policies;

use App\Models\TaxProfile;
use App\Models\User;

class TaxProfilePolicy
{
    public function view(User $user, TaxProfile $taxProfile): bool
    {
        return $user->customer?->id === $taxProfile->customer_id;
    }

    public function update(User $user, TaxProfile $taxProfile): bool
    {
        return $user->customer?->id === $taxProfile->customer_id
            && $taxProfile->deleted_at === null
            && ! $taxProfile->isUsed();
    }

    public function delete(User $user, TaxProfile $taxProfile): bool
    {
        return $user->customer?->id === $taxProfile->customer_id
            && $taxProfile->deleted_at === null;
    }

    public function setDefault(User $user, TaxProfile $taxProfile): bool
    {
        return $user->customer?->id === $taxProfile->customer_id
            && $taxProfile->deleted_at === null;
    }
}
