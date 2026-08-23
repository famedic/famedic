<?php

namespace App\Support;

class PaymentAuthenticationEfevooPayAmounts
{
    public static function threeDsVerificationAmount(): float
    {
        return self::centsToDecimal((int) config('efevoopay.three_ds_verification_amount_cents', 150));
    }

    public static function tokenizationVerificationAmount(): float
    {
        return self::centsToDecimal((int) config('efevoopay.tokenization_verification_amount_cents', 150));
    }

    public static function currency(): string
    {
        return (string) config('efevoopay.verification_currency', 'MXN');
    }

    /**
     * @param  array<string, mixed>  $cardData
     * @return array<string, mixed>
     */
    public static function forGetLink(array $cardData): array
    {
        $cardData['amount'] = self::threeDsVerificationAmount();

        return $cardData;
    }

    /**
     * @param  array<string, mixed>  $cardData
     * @return array<string, mixed>
     */
    public static function forTokenization(array $cardData): array
    {
        unset($cardData['cvv']);
        $cardData['amount'] = self::tokenizationVerificationAmount();

        return $cardData;
    }

    public static function centsToDecimal(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
