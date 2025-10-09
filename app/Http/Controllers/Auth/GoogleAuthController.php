<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Register\RegisterRegularCustomerAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }


    public function handleGoogleCallback(RegisterRegularCustomerAction $action)
    {
        $googleUser = Socialite::driver('google')->user();

        $emailUser = User::where('email', $googleUser->email)->first();

        $authUser = Auth::user();

        if ($emailUser) {
            if ($authUser) {
                if ($authUser->email == $googleUser->email) {
                    return redirect()->route('home');
                }

                return redirect()->route('login');
            }

            Auth::login($emailUser);

            return redirect()->route('home')->flashMessage('Inicio de sesión exitoso.');
        }

        if ($authUser) {
            return redirect()->route('home');
        }

        $regularAccount = $action(
            email: $googleUser->email,
        );

        Auth::login($regularAccount->customer->user);

        return redirect()->route('home')->flashMessage('Inicio de sesión exitoso.');
    }
}
