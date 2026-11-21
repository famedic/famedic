<?php

namespace App\Http\Middleware\Api\V1;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * RC2 Batch 2 · A3: the per-user `throttle:N,M` of a registered-consumer route, run right AFTER
 * api.consumer.authorize and BEFORE api.customer / idempotency / audit / controller.
 *
 * Laravel's middleware priority moves ThrottleRequests ahead of the route's own list (before
 * SubstituteBindings). With auth:sanctum removed from these routes, the throttle would then run before the
 * registered actor is resolved and key the limit by IP instead of by user. This class deliberately does NOT
 * extend ThrottleRequests (priority matching follows parents), so it stays where routes/api/v1.php puts it;
 * the limiter, the key (sha1 of the user id) and the 429 response are Laravel's own.
 */
final class ThrottleRegisteredConsumer
{
    public function __construct(private readonly ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next, int|string $maxAttempts = 60, float|int|string $decayMinutes = 1, string $prefix = ''): mixed
    {
        return $this->throttle->handle($request, $next, $maxAttempts, (float) $decayMinutes, $prefix);
    }
}
