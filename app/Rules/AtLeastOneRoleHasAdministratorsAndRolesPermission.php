<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AtLeastOneRoleHasAdministratorsAndRolesPermission implements ValidationRule
{
    public function __construct(
        protected Role $role
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (
            !in_array('administrators.manage', $value) &&
            Permission::whereName('administrators.manage')->first()->roles()->count() === 1 &&
            $this->role->hasPermissionTo('administrators.manage')
        ) {
            $fail('Al menos 1 rol debe tener el permiso para gestionar administradores, roles y permisos.');
        }
    }
}
