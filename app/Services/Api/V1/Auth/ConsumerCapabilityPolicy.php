<?php

namespace App\Services\Api\V1\Auth;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/** Independent policy component. No route mapping or grants are installed. */
final class ConsumerCapabilityPolicy
{
    /**
     * RC2 Batch 2 · A1 (contract rev7 2b6672e6, L09/L12): Leo may read minimal results/invoices metadata with a
     * literal grant (token + consumer + delegation); documents stay behind step-up/secure links. The capabilities
     * below are never granted to Leo, even if a literal grant were registered (rev7 L11, L15, L18, L20).
     */
    public const LEO_ALWAYS_DENIED = [
        'api:operation:api:v1:orders:results:download',
        'api:operation:api:v1:orders:invoices:download',
        'api:operation:api:v1:orders:cancel',
    ];

    /** rev7 L18 (appointments, native-only) and L20 (tax-profile writes): denied to Leo by prefix. */
    public const LEO_DENIED_PREFIXES = [
        'api:operation:api:v1:laboratory-appointments:',
        'api:operation:api:v1:user:tax-profiles:',
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
            || in_array($capability, self::LEO_ALWAYS_DENIED, true) || self::deniedLeoPrefix($capability))) {
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

    private static function deniedLeoPrefix(string $capability): bool
    {
        foreach (self::LEO_DENIED_PREFIXES as $prefix) {
            if (str_starts_with($capability, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
