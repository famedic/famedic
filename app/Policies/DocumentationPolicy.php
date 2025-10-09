<?php

namespace App\Policies;

use App\Models\Documentation;
use App\Models\User;

class DocumentationPolicy
{
    public function before($user)
    {
        return $user->administrator?->hasPermissionTo('documentation.manage');
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Documentation $documentation): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Documentation $documentation): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Documentation $documentation): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Documentation $documentation): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Documentation $documentation): bool
    {
        return false;
    }
}
