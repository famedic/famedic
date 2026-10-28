<?php

namespace App\Services\LaboratoryPreparation\Admin;

use App\Models\User;

final class LaboratoryPreparationMonitorAccess
{
    public function allows(?User $user): bool
    {
        return (bool) $user?->administrator?->hasRole('Administrador');
    }
}
