<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfEmptyLaboratoryCartItems
{
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->user()->customer->laboratoryCartItems()->ofBrand($request->route('laboratory_brand'))->count() === 0) {
            return redirect()->route('laboratory-tests', ['laboratory_brand' => $request->route('laboratory_brand')]);
        }

        return $next($request);
    }
}
