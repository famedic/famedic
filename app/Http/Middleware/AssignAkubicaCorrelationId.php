<?php

namespace App\Http\Middleware;

use App\Support\Api\V1\AkubicaCorrelationId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds an opaque X-Correlation-ID for every /api/v1 request (P1-A6).
 *
 * - Accepts a valid client header or generates a UUID.
 * - Shares correlation_id with the logging context (sanitized allowlist only).
 * - Echoes X-Correlation-ID on the response when the pipeline returns normally.
 * - Exception JSON/PDF paths also set the header via ApiResponse / controller helpers.
 */
class AssignAkubicaCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/v1', 'api/v1/*')) {
            return $next($request);
        }

        $existing = $request->attributes->get(AkubicaCorrelationId::REQUEST_ATTRIBUTE);
        if (is_string($existing) && $existing !== '') {
            $correlationId = $existing;
        } else {
            $correlationId = AkubicaCorrelationId::resolve(
                AkubicaCorrelationId::incomingHeader($request)
            );
            AkubicaCorrelationId::bind($request, $correlationId);
            Log::shareContext([
                'correlation_id' => $correlationId,
            ]);
        }

        /** @var Response $response */
        $response = $next($request);

        // Prefer attribute after the pipeline so idempotency replay can rebind
        // the original operation correlation id without changing success bodies.
        $final = $request->attributes->get(AkubicaCorrelationId::REQUEST_ATTRIBUTE);
        if (is_string($final) && $final !== '') {
            $correlationId = $final;
        }

        $response->headers->set(AkubicaCorrelationId::HEADER, $correlationId);

        return $response;
    }
}
