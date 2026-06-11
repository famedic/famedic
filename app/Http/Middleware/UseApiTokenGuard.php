<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UseApiTokenGuard
{
    /**
     * Akubica API clients authenticate exclusively via Bearer tokens.
     * Skip Sanctum's web session guard to avoid session bleed in tests/SPAs.
     */
    public function handle(Request $request, Closure $next): Response
    {
        config(['sanctum.guard' => []]);

        return $next($request);
    }
}
