<?php

namespace App\Services\Api\V1\Auth;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

final class LegacyBenefitAccessPolicy
{
    public function denial(User $user, PersonalAccessToken $token, ConsumerIdentityResolution $identity, int $now): ?string
    {
        $context = $identity->context;
        if ($identity->status === 'rejected'
            || ($identity->status === 'verified' && ($context === null
                || ! $context->matches($user, $token, $now)
                || $context->consumerKind === 'leo'))) {
            return 'CONSUMER_NOT_AUTHORIZED';
        }
        return null;
    }
}
