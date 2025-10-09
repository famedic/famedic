<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Tzsk\Otp\Facades\Otp;

class VerifyPhoneController extends Controller
{
    public function index()
    {
        return Inertia::render('Auth/ConfirmPhoneVerification');
    }

    public function store(Request $request): RedirectResponse
    {
        if (!Otp::match($request->code, md5($request->user()->email))) {
            return back()->withErrors([
                'code' => 'El código es incorrecto.'
            ]);
        }

        $request->user()->markPhoneAsVerified();

        return redirect()->intended(route('home', absolute: false))->flashMessage('Tu teléfono ha sido verificado.');
    }
}
