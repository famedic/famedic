<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\URL;

class GenerateLoginLinkForUserAction
{
    public function __invoke(User $user, $redirectUrl = '/home'): string
    {
        return URL::temporarySignedRoute(
            'passwordless.authenticate',
            now()->addDays(3),
            [
                'user' => $user->id,
                'redirect' => $redirectUrl
            ]
        );
    }
}
