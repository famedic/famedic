<?php

namespace App\Support;

use App\Enums\PaymentAuthenticationRecoveryContextType;

class PaymentAuthenticationRecoveryReturnRouteAllowlist
{
    /**
     * Named routes that may be used as a post-verification return.
     *
     * Legacy `return_url` values are accepted only after they resolve to one of these
     * names. Remove that fallback after checkouts stop sending `return_url` and no
     * in-flight 3DS sessions still store `3ds_return_url_{sessionId}` in the session.
     *
     * @return list<string>
     */
    public static function routeNames(): array
    {
        return [
            'payment-methods.index',
            'laboratory.checkout',
            'medical-attention.checkout',
            'medical-attention',
            'online-pharmacy.checkout',
        ];
    }

    public static function isAllowed(?string $routeName): bool
    {
        return $routeName !== null && in_array($routeName, self::routeNames(), true);
    }

    public static function typeForRoute(?string $routeName): ?PaymentAuthenticationRecoveryContextType
    {
        return PaymentAuthenticationRecoveryContextType::fromReturnRouteName($routeName);
    }
}
