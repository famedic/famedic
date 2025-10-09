<?php

namespace App\Policies;

use App\Models\LaboratoryTest;
use App\Models\User;

class LaboratoryTestPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->administrator?->hasPermissionTo('laboratory-tests.manage');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, LaboratoryTest $laboratoryTest): bool
    {
        return $user->administrator?->hasPermissionTo('laboratory-tests.manage');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->administrator?->hasPermissionTo('laboratory-tests.manage.edit');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, LaboratoryTest $laboratoryTest): bool
    {
        return $user->administrator?->hasPermissionTo('laboratory-tests.manage.edit');
    }
}
