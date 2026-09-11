<?php

namespace App\Services\LaboratoryBilling;

use App\Models\Administrator;
use App\Models\User;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

class LaboratoryBillingAccess
{
    public const PERMISSION_INVOICES = 'laboratory-purchases.manage.invoices';

    public const PERMISSION_REPORTS = 'laboratory-purchases.manage.billing-reports';

    public const PERMISSION_MANAGE = 'laboratory-purchases.manage';

    public function allows(?User $user): bool
    {
        $administrator = $user?->administrator;

        if (! $administrator instanceof Administrator) {
            return false;
        }

        try {
            if ($administrator->hasRole('superadmin')) {
                return true;
            }
        } catch (RoleDoesNotExist) {
            // Rol aún no sembrado en este entorno/prueba.
        }

        if ($administrator->roles()->where('roles.id', 1)->exists()) {
            return true;
        }

        return $this->hasPermission($administrator, self::PERMISSION_INVOICES)
            || $this->hasPermission($administrator, self::PERMISSION_REPORTS)
            || $this->hasPermission($administrator, self::PERMISSION_MANAGE);
    }

    public function allowsReports(?User $user): bool
    {
        $administrator = $user?->administrator;

        if (! $administrator instanceof Administrator) {
            return false;
        }

        try {
            if ($administrator->hasRole('superadmin')) {
                return true;
            }
        } catch (RoleDoesNotExist) {
            // Rol aún no sembrado en este entorno/prueba.
        }

        if ($administrator->roles()->where('roles.id', 1)->exists()) {
            return true;
        }

        return $this->hasPermission($administrator, self::PERMISSION_REPORTS)
            || $this->hasPermission($administrator, self::PERMISSION_MANAGE);
    }

    public function authorize(?User $user): void
    {
        if (! $this->allows($user)) {
            abort(403);
        }
    }

    public function authorizeReports(?User $user): void
    {
        if (! $this->allowsReports($user)) {
            abort(403);
        }
    }

    private function hasPermission(Administrator $administrator, string $permission): bool
    {
        try {
            return $administrator->hasPermissionTo($permission);
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
            return false;
        }
    }
}
