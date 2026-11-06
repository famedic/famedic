<?php

namespace App\Http\Middleware\Api\V1;

use App\Http\Responses\ApiResponse;
use App\Services\Api\V1\Auth\BearerPatAuthenticator;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Auth priority applies only where this class is attached: payment-link. */
final class AuthenticatePaymentLinkPat implements AuthenticatesRequests
{
    public function __construct(private readonly BearerPatAuthenticator $authentication) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->authentication->authenticate($request);
        if ($user === null) {
            return ApiResponse::error('UNAUTHENTICATED', 'No autenticado.', 401);
        }
        $previous = $request->getUserResolver();
        $request->setUserResolver(static fn ($guard = null) => $user);
        try {
            return $next($request);
        } finally {
            $request->setUserResolver($previous);
        }
    }
}
