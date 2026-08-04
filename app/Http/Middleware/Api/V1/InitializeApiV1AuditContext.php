<?php

namespace App\Http\Middleware\Api\V1;

use App\Services\Api\V1\Audit\ApiV1AuditContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Creates and hydrates ApiV1AuditContext for the current request.
 *
 * Does NOT insert audit events, read bodies, transform responses,
 * change correlation IDs, or audit routes automatically.
 *
 * Future middleware order on write routes (documented; not applied yet):
 *   force.json → api.correlation → api.token.guard
 *     → auth:sanctum → api.customer → api.idempotency → api.audit (optional)
 *
 * Prefer attaching api.audit after api.correlation so correlation_id is bound.
 * Actor resolution remains explicit at write sites (later instrumentation blocks).
 */
class InitializeApiV1AuditContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/v1', 'api/v1/*')) {
            ApiV1AuditContext::fromRequest($request);
        }

        return $next($request);
    }
}
