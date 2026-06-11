<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->customer) {
            return ApiResponse::error(
                'FORBIDDEN',
                'El usuario no tiene perfil de cliente asociado.',
                403,
            );
        }

        return $next($request);
    }
}
