<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;

class ContactPolicy
{
    public function update(User $user, Contact $contact): bool
    {
        return $user->customer?->id === $contact->customer_id;
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $user->customer?->id === $contact->customer_id;
    }
}
