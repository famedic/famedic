<?php

namespace App\Support;

class PaymentMethodIdentifier
{
    public const HEY_BANCO_PREFIX = 'hey_banco:';

    public static function isHeyBanco(string $paymentMethodId): bool
    {
        return str_starts_with($paymentMethodId, self::HEY_BANCO_PREFIX);
    }

    public static function heyBancoId(string $paymentMethodId): int
    {
        return (int) str_replace(self::HEY_BANCO_PREFIX, '', $paymentMethodId);
    }

    public static function heyBancoPublicId(int $id): string
    {
        return self::HEY_BANCO_PREFIX . $id;
    }
}
