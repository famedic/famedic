<?php

namespace App\Services\Api\V1\Auth;

use App\Contracts\Api\V1\Auth\BackendClientAuthenticator;
use App\Contracts\Api\V1\Auth\ConsumerBindingRepository;
use App\Contracts\Api\V1\Auth\ConsumerIdentityResolver;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/** Dormant adapter. Deliberately NOT bound in AppServiceProvider. */
final class BoundConsumerIdentityResolver implements ConsumerIdentityResolver
{
    public function __construct(private ConsumerBindingRepository $bindings, private BackendClientAuthenticator $backend) {}

    public function resolve(Request $request, User $user, PersonalAccessToken $token): ConsumerIdentityResolution
    {
        try {
            if (! $user->exists || ! $token->exists || (int) $token->tokenable_id !== (int) $user->getKey()
                || $token->tokenable_type !== $user->getMorphClass()) {
                return ConsumerIdentityResolution::rejected();
            }
            $context = $this->bindings->findByTokenId((int) $token->getKey());
            if ($context === null) {
                return ConsumerIdentityResolution::unclassified();
            }
            $proof = $this->backend->authenticate($request);
            $now = \Illuminate\Support\Carbon::now()->timestamp;
            if ($proof === null || $proof->expiresAt <= $now
                || $proof->consumerId !== $context->consumerId || $proof->channelId !== $context->channelId
                || ! $context->matches($user, $token, $now)) {
                return ConsumerIdentityResolution::rejected();
            }

            return ConsumerIdentityResolution::verified($context);
        } catch (\Throwable) {
            // A known or uncertain binding must never become an unclassified legacy bypass.
            return ConsumerIdentityResolution::rejected();
        }
    }
}
