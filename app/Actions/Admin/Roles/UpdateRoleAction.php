<?php

namespace App\Actions\Admin\Roles;

use App\Exceptions\OnlyRoleWithUserAndRolePermissionException;
use Illuminate\Support\Facades\DB;
use App\Models\Role;

class UpdateRoleAction
{
    public function __invoke(string $name, array $permissions, Role $role): Role
    {
        if (
            !in_array('administrators.manage', $permissions) &&
            $role->is_only_role_with_user_and_role_permission
        ) {
            throw new OnlyRoleWithUserAndRolePermissionException();
        }

        // Filter out subpermissions when their parent permission is not selected
        $filteredPermissions = array_filter($permissions, function ($permission) use ($permissions) {
            $parts = explode('.', $permission);
            if (count($parts) > 2) {
                $parent = $parts[0] . '.' . $parts[1];
                return in_array($parent, $permissions);
            }
            return true;
        });

        try {
            DB::beginTransaction();

            $role->update([
                'name' => $name,
            ]);

            $role->syncPermissions($filteredPermissions);

            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }

        return $role;
    }
}
