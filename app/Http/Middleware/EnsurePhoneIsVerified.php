<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePhoneIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $flashMessage = session()->get('flashMessage');

        if (
            ! $request->user() ||
            ! $request->user()->has_verified_phone
        ) {
            return $request->expectsJson()
                ? abort(403, 'Your phone is not verified.')
                : (session()->get('flashMessage') ?
                    redirect()->route('phone.verification.notice')->flashMessage($flashMessage['message'], $flashMessage['type']) :
                    redirect()->route('phone.verification.notice'));
        }

        return $next($request);
    }
}
