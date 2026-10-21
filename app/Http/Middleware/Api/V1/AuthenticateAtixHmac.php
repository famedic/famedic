<?php

namespace App\Http\Middleware\Api\V1;

use App\Services\Api\V1\Integrations\AtixHmacService;
use Closure;
use Illuminate\Http\Request;

final class AuthenticateAtixHmac
{
    public function __construct(private readonly AtixHmacService $hmac) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $failure = $this->hmac->authenticate($request);
        return $failure ?? $next($request);
    }
}
