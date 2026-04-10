<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->intended(route('home', absolute: false) . '?verified=1');
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        if (config('auth.auto_verify_phone_after_email') && ! $user->has_verified_phone) {
            $user->markPhoneAsVerified();
        }

        return redirect()->intended(route('home', absolute: false) . '?verified=1')->flashMessage('Tu dirección de correo electrónico ha sido verificada.');
    }
}
