<?php

namespace App\Services\LaboratoryBilling;

use App\Models\Administrator;
use App\Models\User;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

class LaboratoryBillingAccess
{
    public const PERMISSION_INVOICES = 'laboratory-purchases.manage.invoices';

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

        try {
            return $administrator->hasPermissionTo(self::PERMISSION_INVOICES)
                || $administrator->hasPermissionTo(self::PERMISSION_MANAGE);
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
            return false;
        }
    }

    public function authorize(?User $user): void
    {
        if (! $this->allows($user)) {
            abort(403);
        }
    }
}
