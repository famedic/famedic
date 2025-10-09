<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\URL;

class GenerateInvitationUrlAction
{
    public function __invoke(User $user): string
    {
        return URL::signedRoute('register.invitation', ['user' => $user->id]);
    }
}
