<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->administrator?->hasPermissionTo('customers.manage');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->administrator?->hasPermissionTo('customers.manage');
    }
}
