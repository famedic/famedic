<?php

namespace App\Services\Api\V1\Auth;

use App\Contracts\Api\V1\Auth\ConsumerIdentityResolver;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/** No backend authentication/binding adapter has technical approval yet. */
final class UnavailableConsumerIdentityResolver implements ConsumerIdentityResolver
{
    public function resolve(Request $request, User $user, PersonalAccessToken $token): ConsumerIdentityResolution
    {
        return ConsumerIdentityResolution::unavailable();
    }
}
