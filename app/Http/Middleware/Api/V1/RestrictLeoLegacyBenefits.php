<?php

namespace App\Http\Middleware\Api\V1;

use App\Contracts\Api\V1\Auth\ConsumerIdentityResolver;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Api\V1\Auth\LegacyBenefitAccessPolicy;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final class RestrictLeoLegacyBenefits
{
    public function __construct(
        private readonly ConsumerIdentityResolver $identities,
        private readonly LegacyBenefitAccessPolicy $policy,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if ($user instanceof User && $token instanceof PersonalAccessToken) {
            $identity = $this->identities->resolve($request, $user, $token);
            if ($this->policy->denial($user, $token, $identity, now()->timestamp) !== null) {
                return ApiResponse::error('CONSUMER_NOT_AUTHORIZED', 'Operación no autorizada.', 403);
            }
        }
        // Unavailable/unclassified does not identify Leo. Preserve existing
        // legacy clients while the backend/binding migration remains pending.
        // This is NOT a claim that Leo legacy isolation is operational yet.
        return $next($request);
    }
}
