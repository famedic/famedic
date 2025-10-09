<?php

namespace App\Policies;

use App\Models\OnlinePharmacyPurchase;
use App\Models\User;

class OnlinePharmacyPurchasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->administrator?->hasPermissionTo('online-pharmacy-purchases.manage');
    }

    public function view(User $user, OnlinePharmacyPurchase $onlinePharmacyPurchase): bool
    {
        return $user->customer?->id === $onlinePharmacyPurchase->customer_id || $user->administrator?->hasPermissionTo('online-pharmacy-purchases.manage');
    }

    public function update(User $user, OnlinePharmacyPurchase $onlinePharmacyPurchase): bool
    {
        return $user->customer?->id === $onlinePharmacyPurchase->customer_id || $user->administrator?->hasPermissionTo('online-pharmacy-purchases.manage');
    }

    public function delete(User $user, OnlinePharmacyPurchase $onlinePharmacyPurchase): bool
    {
        return $user->administrator?->hasPermissionTo('online-pharmacy-purchases.manage');
    }
}
