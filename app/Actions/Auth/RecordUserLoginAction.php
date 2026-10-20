<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Http\Request;

class RecordUserLoginAction
{
    public function __invoke(User $user, ?Request $request = null): void
    {
        $user->forceFill([
            'last_login_at' => now(),
        ])->save();
    }
}
