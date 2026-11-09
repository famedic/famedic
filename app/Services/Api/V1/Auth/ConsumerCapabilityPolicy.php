<?php

namespace App\Services\Api\V1\Auth;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/** Independent policy component. No route mapping or grants are installed. */
final class ConsumerCapabilityPolicy
{
    public function denial(User $user, PersonalAccessToken $token, ConsumerIdentityResolution $identity, string $capability, int $now): ?string
    {
        $context = $identity->context;
        if ($identity->status !== 'verified' || ! $context || ! $context->matches($user, $token, $now)) {
            return 'CONSUMER_NOT_AUTHORIZED';
        }
        if (! in_array($context->consumerKind, ['leo', 'native'], true)) {
            return 'CONSUMER_NOT_AUTHORIZED';
        }
        if ($context->consumerKind === 'leo' && (str_starts_with($capability, 'payments:')
            || $capability === 'cart:write' || str_starts_with($capability, 'legacy-benefits:') || $capability === PaymentLinkAccessPolicy::CAPABILITY)) {
            return 'CONSUMER_NOT_AUTHORIZED';
        }
        if ($capability === '' || ! is_array($token->abilities) || in_array('*', $token->abilities, true)
            || ! in_array($capability, $token->abilities, true)
            || ! in_array($capability, $context->consumerCapabilities, true)
            || ! in_array($capability, $context->delegationCapabilities, true)) {
            return 'CONSUMER_NOT_AUTHORIZED';
        }

        return null;
    }
}
