<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    protected $guarded = [];

    protected function isOnlyRoleWithUserAndRolePermission(): Attribute
    {
        $usersAndRolesPermission = 'administrators.manage';
        $rolePermissions = $this->permissions->pluck('name')->toArray();

        return Attribute::make(
            get: function () use ($usersAndRolesPermission, $rolePermissions) {
                if (!in_array($usersAndRolesPermission, $rolePermissions)) {
                    return false;
                }

                return self::permission($usersAndRolesPermission)->count() === 1;
            },
        );
    }
}
