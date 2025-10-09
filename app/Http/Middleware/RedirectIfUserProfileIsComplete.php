<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfUserProfileIsComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        return $request->user()->profile_is_complete ?
            redirect()->route('home') :
            $next($request);
    }
}
