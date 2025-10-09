<?php

namespace App\Policies;

use App\Models\LaboratoryPurchase;
use App\Models\User;

class LaboratoryPurchasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->administrator?->hasPermissionTo('laboratory-purchases.manage');
    }

    public function view(User $user, LaboratoryPurchase $laboratoryPurchase): bool
    {
        return $user->customer?->id === $laboratoryPurchase->customer_id || $user->administrator?->hasPermissionTo('laboratory-purchases.manage');
    }

    public function update(User $user, LaboratoryPurchase $laboratoryPurchase): bool
    {
        return $user->customer?->id === $laboratoryPurchase->customer_id || $user->administrator?->hasPermissionTo('laboratory-purchases.manage');
    }

    public function delete(User $user, LaboratoryPurchase $laboratoryPurchase): bool
    {
        return $user->administrator?->hasPermissionTo('laboratory-purchases.manage.cancel') && ! $laboratoryPurchase->trashed();
    }
}
