<?php

namespace App\Actions\Admin\Roles;

use App\Models\Role;

class CreateRoleAction
{
    public function __invoke(string $name, array $permissions): Role
    {
        // Filter out subpermissions when their parent permission is not selected
        $filteredPermissions = array_filter($permissions, function ($permission) use ($permissions) {
            $parts = explode('.', $permission);
            if (count($parts) > 2) {
                $parent = $parts[0] . '.' . $parts[1];
                return in_array($parent, $permissions);
            }
            return true;
        });

        return Role::create(['name' => $name])->givePermissionTo($filteredPermissions);
    }
}
