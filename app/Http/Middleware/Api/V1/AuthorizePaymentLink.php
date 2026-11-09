<?php

namespace App\Http\Middleware\Api\V1;

use App\Services\Api\V1\Auth\PaymentLinkAuthorization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizePaymentLink
{
    public function __construct(private readonly PaymentLinkAuthorization $authorization) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->authorization->assertAllowed($request);
        try {
            return $next($request);
        } finally {
            \App\Services\Api\V1\Auth\ConsumerRequestScope::clear($request);
        }
    }
}
