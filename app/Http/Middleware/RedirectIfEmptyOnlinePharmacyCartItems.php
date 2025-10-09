<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfEmptyOnlinePharmacyCartItems
{
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->user()->customer->onlinePharmacyCartItems()->count() === 0) {
            return redirect()->route('online-pharmacy');
        }

        return $next($request);
    }
}
