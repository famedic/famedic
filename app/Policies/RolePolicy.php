<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function before($user, $ability)
    {
        return $user->administrator?->hasPermissionTo('administrators.manage');
    }

    public function viewAny(User $user)
    {
        //
    }

    public function create(User $user)
    {
        //
    }

    public function update(User $user, Role $role)
    {
        //
    }

    public function delete(User $user, Role $role)
    {
        //
    }
}
