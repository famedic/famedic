<?php

namespace App\Contracts\Api\V1\Auth;

use App\Models\User;
use App\Services\Api\V1\Auth\ConsumerIdentityResolution;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

interface ConsumerIdentityResolver
{
    /**
     * An adapter must verify backend credentials AND the server-side PAT binding.
     * Known bindings with missing/invalid proof MUST return rejected, never
     * unclassified/unavailable. Public request headers alone are not proof.
     */
    public function resolve(Request $request, User $user, PersonalAccessToken $token): ConsumerIdentityResolution;
}
