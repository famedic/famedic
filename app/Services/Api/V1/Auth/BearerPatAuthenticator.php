<?php

namespace App\Services\Api\V1\Auth;

use App\Models\User;
use Illuminate\Auth\RequestGuard;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Http\Request;
use Laravel\Sanctum\Guard;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

final class BearerPatAuthenticator
{
    public function authenticate(Request $request): ?User
    {
        $bearer = $request->bearerToken();
        if (! is_string($bearer) || $bearer === '') {
            return null;
        }

        // Reuse Sanctum hash/provider/expiry/revocation/callback semantics, but
        // give its session lookup only guest guards. No global config, web guard,
        // default driver or cached request guard is modified or reused.
        $sessionless = new class($request) implements Factory
        {
            public function __construct(private readonly Request $request) {}

            public function guard($name = null)
            {
                return new RequestGuard(static fn () => null, $this->request);
            }

            public function shouldUse($name)
            {
                throw new \LogicException('Sessionless factory cannot select a session guard.');
            }
        };
        $guard = new Guard($sessionless, config('sanctum.expiration'), config('auth.guards.sanctum.provider'));
        $user = $guard($request);
        if (! $user instanceof User || ! $user->currentAccessToken() instanceof PersonalAccessToken) {
            return null;
        }

        // A custom Sanctum retrieval callback cannot replace the supplied Bearer
        // with another credential in this payment-specific authentication path.
        $model = Sanctum::$personalAccessTokenModel;
        $presented = $model::findToken($bearer);

        return $presented !== null
            && (int) $presented->getKey() === (int) $user->currentAccessToken()->getKey()
            ? $user : null;
    }
}
