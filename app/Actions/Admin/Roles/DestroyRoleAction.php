<?php

namespace App\Actions\Admin\Roles;

use App\Exceptions\OnlyRoleWithUserAndRolePermissionException;
use App\Models\Role;

class DestroyRoleAction
{
    public function __invoke(Role $role): void
    {
        if (
            $role->is_only_role_with_user_and_role_permission
        ) {
            throw new OnlyRoleWithUserAndRolePermissionException();
        }

        $role->delete();
    }
}
