<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

class PasswordlessAuthenticationController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = User::findOrFail($request->user);

        if (!URL::hasValidSignature($request)) {
            return redirect('/login');
        }

        Auth::login($user);

        $request->session()->regenerate();

        $requestUrl = $request->redirect;

        if (URL::isValidUrl($requestUrl)) {
            return redirect($requestUrl)->flashMessage('¡Bienvenido a Famedic!');
        }

        return redirect('/home')->flashMessage('¡Bienvenido a Famedic!');
    }
}
