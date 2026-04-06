<?php

namespace App\Policies;

use App\Models\Coupon;
use App\Models\User;

class CouponPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->administrator?->hasPermissionTo('coupons.manage') ?? false;
    }

    public function view(User $user, Coupon $coupon): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Coupon $coupon): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Coupon $coupon): bool
    {
        return $this->viewAny($user);
    }
}
