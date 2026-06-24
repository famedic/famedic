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
        $ratePercent = (float) config('efevoopay.commission.rate_percent', 2.9);
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
}
