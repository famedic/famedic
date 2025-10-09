<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAppointmentConfirmed
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->route('laboratory_appointment')->confirmed_at) {
            return redirect()->route('laboratory.checkout', ['laboratory_brand' => $request->route('laboratory_brand')]);
        }

        return $next($request);
    }
}
