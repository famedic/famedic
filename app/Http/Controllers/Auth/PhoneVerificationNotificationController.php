<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PhoneVerificationNotificationController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        if ($request->user()->has_verified_phone) {
            return redirect()->intended(route('home', absolute: false));
        }

        $request->user()->sendPhoneVerificationNotification();

        return redirect()->route('phone.verification')->flashMessage('Se ha enviado un código de verificación a tu teléfono.');
    }
}
