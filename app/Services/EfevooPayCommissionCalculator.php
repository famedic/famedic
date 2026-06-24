<?php

namespace App\Services;

/**
 * Comisión EfevooPay: porcentaje sobre el monto cobrado + IVA sobre la comisión.
 *
 * El monto se expresa en centavos; la comisión base se redondea a centavos
 * y el IVA se calcula sobre esa base (redondeo a centavos).
 */
class EfevooPayCommissionCalculator
{
    /**
     * @return array{
     *     base_cents: int,
     *     vat_cents: int,
     *     total_cents: int,
     *     rate_percent: float,
     *     vat_rate_percent: float
     * }
     */
    public static function calculate(int $amountCents): array
    {
        $amountCents = max(0, $amountCents);
        $ratePercent = (float) config('efevoopay.commission.rate_percent', 2.99);
        $vatRatePercent = (float) config('efevoopay.commission.vat_rate_percent', 16);

        $baseCents = (int) round($amountCents * $ratePercent / 100, 0, PHP_ROUND_HALF_UP);
        $vatCents = intdiv($baseCents * (int) round($vatRatePercent * 100), 10_000);
        $totalCents = $baseCents + $vatCents;

        return [
            'base_cents' => $baseCents,
            'vat_cents' => $vatCents,
            'total_cents' => $totalCents,
            'rate_percent' => $ratePercent,
            'vat_rate_percent' => $vatRatePercent,
        ];
    }

    /**
     * @return array{
     *     base_cents: int,
     *     vat_cents: int,
     *     total_cents: int,
     *     formatted_base: string,
     *     formatted_vat: string,
     *     formatted_total: string,
     *     rate_percent: float,
     *     vat_rate_percent: float
     * }
     */
    public static function present(int $amountCents): array
    {
        $calc = self::calculate($amountCents);

        return [
            ...$calc,
            'formatted_base' => formattedCentsPrice($calc['base_cents']),
            'formatted_vat' => formattedCentsPrice($calc['vat_cents']),
            'formatted_total' => formattedCentsPrice($calc['total_cents']),
        ];
    }

    public static function isEfevooPayTransaction(\App\Models\Transaction $transaction): bool
    {
        $method = strtolower((string) ($transaction->payment_method ?? ''));
        $gateway = strtolower((string) ($transaction->gateway ?? ''));

        return $method === 'efevoopay' || $gateway === 'efevoopay';
    }

    public static function resolveChargedAmountCents(
        \App\Models\Transaction $transaction,
        ?int $fallbackAmountCents = null,
    ): int {
        foreach ([
            $transaction->transaction_amount_cents,
            data_get($transaction->details, 'amount_charged_cents'),
            data_get($transaction->details, 'payment_details.amount_cents'),
            $fallbackAmountCents,
        ] as $amount) {
            if ($amount !== null && $amount !== '' && (int) $amount > 0) {
                return (int) $amount;
            }
        }

        return 0;
    }

    /**
     * Comisión total EfevooPay para exportes/UI, o null si no aplica.
     */
    public static function commissionCentsForTransaction(
        \App\Models\Transaction $transaction,
        ?int $fallbackAmountCents = null,
    ): ?int {
        if (! self::isEfevooPayTransaction($transaction)) {
            return null;
        }

        $charged = self::resolveChargedAmountCents($transaction, $fallbackAmountCents);

        return $charged > 0 ? self::calculate($charged)['total_cents'] : 0;
    }
}
