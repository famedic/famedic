<?php

namespace App\Policies;

use App\Models\PromoCode;
use App\Models\User;

class PromoCodePolicy
{
    private function hasCouponPermission(User $user, string $permission): bool
    {
        if (! $user->administrator) {
            return false;
        }

        return $user->administrator->hasPermissionTo($permission)
            || $user->administrator->hasPermissionTo('coupons.manage');
    }

    public function viewAny(User $user): bool
    {
        return $this->hasCouponPermission($user, 'cupones.view');
    }

    public function view(User $user, PromoCode $promoCode): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasCouponPermission($user, 'cupones.create');
    }

    public function update(User $user, PromoCode $promoCode): bool
    {
        return $this->hasCouponPermission($user, 'cupones.edit');
    }
}
