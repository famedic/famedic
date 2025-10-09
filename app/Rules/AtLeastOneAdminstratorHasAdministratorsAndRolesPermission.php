<?php

namespace App\Rules;

use App\Models\Administrator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Spatie\Permission\Models\Role;

class AtLeastOneAdminstratorHasAdministratorsAndRolesPermission implements ValidationRule
{
    public function __construct(
        protected Administrator $administrator
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $rolesWithAdministratorsAndRolesPermission = Role::whereHas(
            'permissions',
            function ($query) {
                $query->where('name', 'administrators.manage');
            }
        )->pluck('name')->toArray();

        $roleWithAdministratorsAndRolesPermissionWasntSelected = empty(array_intersect($rolesWithAdministratorsAndRolesPermission, $value));

        if ($this->administrator?->is_only_administrator_with_administrators_and_roles_permission && $roleWithAdministratorsAndRolesPermissionWasntSelected) {
            $fail('Al menos 1 administrador debe tener asignado un rol con el permiso para gestionar usuarios, roles y permisos.');
        }
    }
}
