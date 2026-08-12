<?php

namespace App\Policies;

use App\Models\LaboratoryPurchase;
use App\Models\User;
use App\Services\LaboratoryBilling\LaboratoryBillingAccess;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

class LaboratoryPurchasePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->administratorHasPermission($user, 'laboratory-purchases.manage');
    }

    public function view(User $user, LaboratoryPurchase $laboratoryPurchase): bool
    {
        if ($user->customer?->id === $laboratoryPurchase->customer_id) {
            return true;
        }

        // Facturación puede abrir el pedido para cargar/reemplazar documentos.
        return $this->administratorHasPermission($user, 'laboratory-purchases.manage')
            || $this->administratorHasPermission($user, LaboratoryBillingAccess::PERMISSION_INVOICES)
            || $this->isSuperAdmin($user);
    }

    public function update(User $user, LaboratoryPurchase $laboratoryPurchase): bool
    {
        if ($user->customer?->id === $laboratoryPurchase->customer_id) {
            return true;
        }

        return $this->administratorHasPermission($user, 'laboratory-purchases.manage');
    }

    /**
     * Carga o reemplazo de PDF/XML de factura de laboratorio.
     */
    public function uploadInvoice(User $user, LaboratoryPurchase $laboratoryPurchase): bool
    {
        if ($laboratoryPurchase->trashed()) {
            return false;
        }

        return $this->administratorHasPermission($user, LaboratoryBillingAccess::PERMISSION_INVOICES)
            || $this->administratorHasPermission($user, LaboratoryBillingAccess::PERMISSION_MANAGE)
            || $this->isSuperAdmin($user);
    }

    public function delete(User $user, LaboratoryPurchase $laboratoryPurchase): bool
    {
        return $this->administratorHasPermission($user, 'laboratory-purchases.manage.cancel')
            && ! $laboratoryPurchase->trashed();
    }

    private function administratorHasPermission(User $user, string $permission): bool
    {
        $administrator = $user->administrator;

        if (! $administrator) {
            return false;
        }

        try {
            return $administrator->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    private function isSuperAdmin(User $user): bool
    {
        $administrator = $user->administrator;

        if (! $administrator) {
            return false;
        }

        try {
            if ($administrator->hasRole('superadmin')) {
                return true;
            }
        } catch (RoleDoesNotExist) {
            // ignore
        }

        return $administrator->roles()->where('roles.id', 1)->exists();
    }
}
