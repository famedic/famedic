<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfPhoneVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $flashMessage = session()->get('flashMessage');

        if (
            $request->user()->has_verified_phone
        ) {
            return session()->get('flashMessage') ?
                redirect()->intended(route('home', absolute: false))->flashMessage($flashMessage['message'], $flashMessage['type']) :
                redirect()->intended(route('home', absolute: false));
        }

        return $next($request);
    }
}
