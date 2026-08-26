<?php

namespace App\Http\Middleware;

use App\Support\EfevooPayGatewayMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEfevooMockGateway
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            app()->environment(['local', 'testing']) && EfevooPayGatewayMode::usesMock(),
            404
        );

        return $next($request);
    }
}
