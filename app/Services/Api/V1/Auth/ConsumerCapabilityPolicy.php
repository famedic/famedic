<?php

namespace App\Services\Api\V1\Auth;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/** Independent policy component. No route mapping or grants are installed. */
final class ConsumerCapabilityPolicy
{
    /**
     * RC2 R02/R03 (D5): results and invoices stay protected behind native step-up flows. Leo never reads
     * their metadata, even if a literal grant were registered (no widening; matches OpenAPI allowlist [native]).
     */
    public const LEO_DENIED_PROTECTED_READS = [
        'api:operation:api:v1:orders:results:index',
        'api:operation:api:v1:orders:results',
        'api:operation:api:v1:orders:invoices:index',
        'api:operation:api:v1:orders:invoices',
    ];

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
            || $capability === 'cart:write' || str_starts_with($capability, 'legacy-benefits:') || $capability === PaymentLinkAccessPolicy::CAPABILITY
            || in_array($capability, self::LEO_DENIED_PROTECTED_READS, true))) {
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
