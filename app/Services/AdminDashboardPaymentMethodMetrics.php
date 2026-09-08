<?php

namespace App\Services;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AdminDashboardPaymentMethodMetrics
{
    private const TIMEZONE = 'America/Monterrey';

    private const METHODS = [
        'paypal' => [
            'label' => 'PayPal',
            'amountKey' => 'paypalAmountCents',
            'formattedAmountKey' => 'paypalFormattedAmount',
            'color' => '#0070BA',
        ],
        'efevoopay' => [
            'label' => 'EfevooPay',
            'amountKey' => 'efevoopayAmountCents',
            'formattedAmountKey' => 'efevoopayFormattedAmount',
            'color' => '#7C3AED',
        ],
        'savingsBank' => [
            'label' => 'Caja de ahorro',
            'amountKey' => 'savingsBankAmountCents',
            'formattedAmountKey' => 'savingsBankFormattedAmount',
            'color' => '#F59E0B',
        ],
        'other' => [
            'label' => 'Otro',
            'amountKey' => 'otherAmountCents',
            'formattedAmountKey' => 'otherFormattedAmount',
            'color' => '#6B7280',
        ],
    ];

    /**
     * Builds daily payment-method metrics from the same purchase records used by
     * the dashboard totals. Method evidence priority: completed transaction,
     * transaction gateway/provider/method fields, then legacy purchase fields.
     */
    public function build(Collection $purchaseGroups, Carbon $startDate, Carbon $endDate): array
    {
        $startDate = $startDate->copy()->setTimezone(self::TIMEZONE)->startOfDay();
        $endDate = $endDate->copy()->setTimezone(self::TIMEZONE)->endOfDay();

        if ($startDate->gt($endDate)) {
            return $this->emptyMetrics();
        }

        $buckets = $this->emptyBuckets($startDate, $endDate);
        $seenPurchases = [];

        $purchaseGroups->each(function (Collection $purchases, string $type) use (&$buckets, &$seenPurchases, $startDate, $endDate): void {
            $purchases->each(function ($purchase) use (&$buckets, &$seenPurchases, $type, $startDate, $endDate): void {
                $createdAt = $purchase->created_at?->copy()->setTimezone(self::TIMEZONE);

                if (! $createdAt || $createdAt->lt($startDate) || $createdAt->gt($endDate)) {
                    return;
                }

                $purchaseKey = $this->purchaseKey((string) $type, $purchase);
                if (isset($seenPurchases[$purchaseKey])) {
                    return;
                }
                $seenPurchases[$purchaseKey] = true;

                $method = $this->normalizeKey($this->representativeTransaction($purchase->transactions ?? collect()), $purchase);
                $dateKey = $createdAt->toDateString();

                if (! isset($buckets[$dateKey])) {
                    return;
                }

                $amountKey = self::METHODS[$method]['amountKey'];
                $buckets[$dateKey][$method]++;
                $buckets[$dateKey][$amountKey] += (int) ($purchase->total_cents ?? 0);
            });
        });

        $dataPoints = collect($buckets)
            ->map(function (array $bucket): array {
                foreach (self::METHODS as $method) {
                    $bucket[$method['formattedAmountKey']] = formattedCentsPrice($bucket[$method['amountKey']]);
                }

                return $bucket;
            })
            ->values();

        return [
            'dataPoints' => $dataPoints->all(),
            'series' => $this->series($dataPoints),
        ];
    }

    private function representativeTransaction(iterable $transactions): ?Transaction
    {
        $transactions = collect($transactions);

        return $transactions->first(fn (Transaction $transaction): bool => $transaction->isSuccessfulPayment())
            ?? $transactions->first();
    }

    private function normalizeKey(?Transaction $transaction, object $purchase): string
    {
        if (! $transaction && (int) ($purchase->total_cents ?? 0) === 0 && (int) ($purchase->coupon_discount_cents ?? 0) > 0) {
            return 'savingsBank';
        }

        $raw = strtolower(trim((string) (
            $transaction?->gateway
            ?? $transaction?->payment_provider
            ?? $transaction?->payment_method
            ?? $purchase->payment_method
            ?? $purchase->payment_method_type
            ?? $purchase->gateway
            ?? ''
        )));
        $method = strtolower(trim((string) ($transaction?->payment_method ?? '')));
        $provider = strtolower(trim((string) ($transaction?->payment_provider ?? '')));
        $gateway = strtolower(trim((string) ($transaction?->gateway ?? '')));
        $gatewayTransactionId = strtolower(trim((string) ($transaction?->gateway_transaction_id ?? '')));

        if ($raw === 'paypal' || $method === 'paypal' || $provider === 'paypal' || $gateway === 'paypal') {
            return 'paypal';
        }

        if (
            $raw === 'efevoopay'
            || $method === 'efevoopay'
            || $gateway === 'efevoopay'
            || str_starts_with($gatewayTransactionId, 'sim_')
        ) {
            return 'efevoopay';
        }

        if (in_array($raw, ['odessa', 'payroll', 'nomina', 'nómina', 'credit', 'coupon_balance'], true)
            || in_array($method, ['odessa', 'credit', 'coupon_balance'], true)
            || in_array($gateway, ['odessa', 'coupon_balance'], true)
        ) {
            return 'savingsBank';
        }

        return 'other';
    }

    private function emptyBuckets(Carbon $startDate, Carbon $endDate): array
    {
        return collect($startDate->toPeriod($endDate, '1 day'))
            ->mapWithKeys(function (Carbon $date): array {
                $bucket = [
                    'date' => $date->toDateString(),
                    'label' => $date->isoFormat('MMM D'),
                    'fullDateLabel' => $date->isoFormat('D [de] MMMM [de] Y'),
                ];

                foreach (self::METHODS as $key => $method) {
                    $bucket[$key] = 0;
                    $bucket[$method['amountKey']] = 0;
                    $bucket[$method['formattedAmountKey']] = formattedCentsPrice(0);
                }

                return [$date->toDateString() => $bucket];
            })
            ->all();
    }

    private function series(Collection $dataPoints): array
    {
        return collect(self::METHODS)
            ->reject(fn (array $method, string $key): bool => $key === 'other' && (int) $dataPoints->sum('other') === 0)
            ->map(fn (array $method, string $key): array => [
                'key' => $key,
                'label' => $method['label'],
                'amountKey' => $method['amountKey'],
                'formattedAmountKey' => $method['formattedAmountKey'],
                'color' => $method['color'],
            ])
            ->values()
            ->all();
    }

    private function purchaseKey(string $type, object $purchase): string
    {
        return $type.'#'.($purchase->id ?? spl_object_id($purchase));
    }

    private function emptyMetrics(): array
    {
        return [
            'dataPoints' => [],
            'series' => [],
        ];
    }
}
