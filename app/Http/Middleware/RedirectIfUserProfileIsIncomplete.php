<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfUserProfileIsIncomplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $flashMessage = session()->get('flashMessage');
        return !$request->user()->profile_is_complete ?
            ($flashMessage ?
                redirect()->route('complete-profile')->flashMessage($flashMessage['message'], $flashMessage['type']) :
                redirect()->route('complete-profile')) :
            $next($request);
    }
}
