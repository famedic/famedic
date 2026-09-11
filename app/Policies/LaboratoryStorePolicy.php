<?php

namespace App\Policies;

use App\Models\LaboratoryStore;
use App\Models\User;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class LaboratoryStorePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->administratorHasPermission($user, 'laboratory-stores.manage');
    }

    public function view(User $user, LaboratoryStore $laboratoryStore): bool
    {
        return $this->administratorHasPermission($user, 'laboratory-stores.manage');
    }

    public function update(User $user, LaboratoryStore $laboratoryStore): bool
    {
        return $this->administratorHasPermission($user, 'laboratory-stores.manage.edit');
    }

    public function delete(User $user, LaboratoryStore $laboratoryStore): bool
    {
        return $this->administratorHasPermission($user, 'laboratory-stores.manage.edit');
    }

    public function restore(User $user, LaboratoryStore $laboratoryStore): bool
    {
        return $this->administratorHasPermission($user, 'laboratory-stores.manage.edit');
    }

    private function administratorHasPermission(User $user, string $permission): bool
    {
        if (! $user->administrator) {
            return false;
        }

        try {
            return $user->administrator->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
