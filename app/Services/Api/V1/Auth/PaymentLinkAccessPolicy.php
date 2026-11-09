<?php

namespace App\Services\Api\V1\Auth;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

final class PaymentLinkAccessPolicy
{
    public const CAPABILITY = 'akubica:payment-link:create';

    public function denial(User $user, PersonalAccessToken $token, ConsumerIdentityResolution $identity, int $now): ?string
    {
        $context = $identity->context;
        if ($identity->status !== 'verified' || $context === null || ! $context->matches($user, $token, $now)) {
            return 'CONSUMER_NOT_AUTHORIZED';
        }
        if ($context->consumerKind === 'leo') {
            return 'LEO_PAYMENT_LINK_FORBIDDEN';
        }
        $abilities = $token->abilities ?? [];
        if ($context->consumerKind !== 'native'
            || ! is_array($abilities)
            || in_array('*', $abilities, true)
            || ! in_array(self::CAPABILITY, $abilities, true)
            || ! in_array(self::CAPABILITY, $context->consumerCapabilities, true)
            || ! in_array(self::CAPABILITY, $context->delegationCapabilities, true)) {
            return 'CONSUMER_NOT_AUTHORIZED';
        }

        return null;
    }
}
