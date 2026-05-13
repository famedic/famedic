<?php

namespace App\Policies;

use App\Models\Coupon;
use App\Models\User;

class CouponPolicy
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

    public function view(User $user, Coupon $coupon): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasCouponPermission($user, 'cupones.create');
    }

    public function update(User $user, Coupon $coupon): bool
    {
        return $this->hasCouponPermission($user, 'cupones.edit');
    }

    public function delete(User $user, Coupon $coupon): bool
    {
        return $this->hasCouponPermission($user, 'cupones.delete');
    }

    public function configure(User $user): bool
    {
        return $this->hasCouponPermission($user, 'cupones.config');
    }

    /**
     * Aprobar o rechazar solicitudes de cambios/asignaciones de cupones (rol autorizador o superadmin).
     */
    public function approveRequests(User $user): bool
    {
        if (! $user->administrator) {
            return false;
        }

        return $user->administrator->hasAnyRole(['autorizador', 'superadmin']);
    }
}
