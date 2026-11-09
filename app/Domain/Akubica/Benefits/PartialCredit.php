<?php

declare(strict_types=1);

namespace App\Domain\Akubica\Benefits;

/** Arithmetic only; does not debit or finalize a purchase. Financial approval pending. */
final class PartialCredit
{
    public static function preview(int $remaining, int $subtotal): int
    {
        if ($remaining < 0 || $subtotal < 0) {
            throw new BenefitFailure('INVALID_MONEY');
        }

        return min($remaining, $subtotal);
    }

    public static function remainingAfterConfirmedDebit(int $remaining, int $debit): int
    {
        if ($remaining < 0 || $debit <= 0 || $debit > $remaining) {
            throw new BenefitFailure('INSUFFICIENT_CREDIT');
        }

        return $remaining - $debit;
    }
}
