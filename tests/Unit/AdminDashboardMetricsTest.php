<?php

use App\Actions\BuildDailyChartDataAction;
use App\Models\LaboratoryPurchase;
use App\Models\Transaction;
use App\Services\AdminDashboardPaymentMethodMetrics;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

function dashboardPurchase(
    int $id,
    string $createdAt,
    int $totalCents,
    array $transactions = [],
    array $extra = []
): LaboratoryPurchase {
    $purchase = new LaboratoryPurchase(array_merge([
        'id' => $id,
        'created_at' => Carbon::parse($createdAt, 'UTC'),
        'total_cents' => $totalCents,
    ], $extra));

    $purchase->setRelation('transactions', new EloquentCollection($transactions));

    return $purchase;
}

function dashboardTransaction(array $attributes): Transaction
{
    return new Transaction(array_merge([
        'transaction_amount_cents' => 1000,
        'payment_method' => null,
        'reference_id' => null,
    ], $attributes));
}

test('daily chart count, average and total use the same records and selected period', function () {
    $action = new BuildDailyChartDataAction;
    $purchases = collect([
        dashboardPurchase(1, '2026-09-01 06:00:00', 10000),
        dashboardPurchase(2, '2026-09-01 14:00:00', 25000),
        dashboardPurchase(3, '2026-09-02 14:00:00', 15000),
        dashboardPurchase(4, '2026-09-04 14:00:00', 99999),
    ]);

    $chart = $action(
        $purchases,
        Carbon::parse('2026-09-01', 'America/Monterrey'),
        Carbon::parse('2026-09-02', 'America/Monterrey'),
    );

    expect($chart['count'])->toBe(3)
        ->and($chart['total'])->toBe('$500.00 MXN')
        ->and($chart['averagePerDay'])->toBe('$250.00 MXN')
        ->and($chart['dataPoints'][0]['count'])->toBe(2)
        ->and($chart['dataPoints'][1]['count'])->toBe(1);
});

test('daily chart returns zero purchases for an empty set', function () {
    $chart = (new BuildDailyChartDataAction)(collect([]));

    expect($chart['count'])->toBe(0)
        ->and($chart['total'])->toBe('$0.00 MXN')
        ->and($chart['averagePerDay'])->toBe('$0.00 MXN');
});

test('payment methods group purchases by day and method', function () {
    $metrics = (new AdminDashboardPaymentMethodMetrics)->build(
        collect([
            'laboratory' => collect([
                dashboardPurchase(1, '2026-09-01 12:00:00', 10000, [
                    dashboardTransaction(['payment_method' => 'paypal', 'payment_status' => 'completed']),
                ]),
                dashboardPurchase(2, '2026-09-01 13:00:00', 20000, [
                    dashboardTransaction(['payment_method' => 'efevoopay', 'gateway' => 'efevoopay', 'gateway_status' => 'completed']),
                ]),
                dashboardPurchase(3, '2026-09-02 12:00:00', 30000, [
                    dashboardTransaction(['payment_method' => 'odessa', 'payment_status' => 'credit']),
                ]),
            ]),
        ]),
        Carbon::parse('2026-09-01', 'America/Monterrey'),
        Carbon::parse('2026-09-02', 'America/Monterrey'),
    );

    expect($metrics['dataPoints'])->toHaveCount(2)
        ->and($metrics['dataPoints'][0]['paypal'])->toBe(1)
        ->and($metrics['dataPoints'][0]['paypalAmountCents'])->toBe(10000)
        ->and($metrics['dataPoints'][0]['efevoopay'])->toBe(1)
        ->and($metrics['dataPoints'][0]['efevoopayAmountCents'])->toBe(20000)
        ->and($metrics['dataPoints'][1]['savingsBank'])->toBe(1)
        ->and($metrics['dataPoints'][1]['savingsBankAmountCents'])->toBe(30000);
});

test('payment methods normalize paypal efevoopay and savings bank', function () {
    $metrics = (new AdminDashboardPaymentMethodMetrics)->build(
        collect([
            'laboratory' => collect([
                dashboardPurchase(1, '2026-09-01 12:00:00', 10000, [
                    dashboardTransaction(['payment_method' => null, 'payment_provider' => 'paypal', 'gateway_status' => 'COMPLETED']),
                ]),
                dashboardPurchase(2, '2026-09-01 13:00:00', 20000, [
                    dashboardTransaction(['payment_method' => null, 'gateway' => 'efevoopay', 'gateway_status' => 'completed']),
                ]),
                dashboardPurchase(3, '2026-09-01 14:00:00', 30000, [
                    dashboardTransaction(['payment_method' => 'coupon_balance', 'gateway' => 'coupon_balance', 'gateway_status' => 'completed']),
                ]),
            ]),
        ]),
        Carbon::parse('2026-09-01', 'America/Monterrey'),
        Carbon::parse('2026-09-01', 'America/Monterrey'),
    );

    expect($metrics['dataPoints'][0]['paypal'])->toBe(1)
        ->and($metrics['dataPoints'][0]['efevoopay'])->toBe(1)
        ->and($metrics['dataPoints'][0]['savingsBank'])->toBe(1)
        ->and(collect($metrics['series'])->pluck('key')->all())->toBe(['paypal', 'efevoopay', 'savingsBank'])
        ->and(collect($metrics['series'])->pluck('color', 'key')->all())->toMatchArray([
            'paypal' => '#0070BA',
            'efevoopay' => '#7C3AED',
            'savingsBank' => '#F59E0B',
        ]);
});

test('payment methods include days without purchases as zero buckets', function () {
    $metrics = (new AdminDashboardPaymentMethodMetrics)->build(
        collect([
            'laboratory' => collect([
                dashboardPurchase(1, '2026-09-01 12:00:00', 10000, [
                    dashboardTransaction(['payment_method' => 'paypal', 'payment_status' => 'completed']),
                ]),
            ]),
        ]),
        Carbon::parse('2026-09-01', 'America/Monterrey'),
        Carbon::parse('2026-09-03', 'America/Monterrey'),
    );

    expect($metrics['dataPoints'])->toHaveCount(3)
        ->and($metrics['dataPoints'][1]['date'])->toBe('2026-09-02')
        ->and($metrics['dataPoints'][1]['paypal'])->toBe(0)
        ->and($metrics['dataPoints'][1]['efevoopay'])->toBe(0)
        ->and($metrics['dataPoints'][1]['savingsBank'])->toBe(0)
        ->and($metrics['dataPoints'][1]['paypalAmountCents'])->toBe(0);
});

test('payment methods count a purchase with multiple transactions once and use completed transaction', function () {
    $purchase = dashboardPurchase(1, '2026-09-01 12:00:00', 10000, [
        dashboardTransaction(['payment_method' => 'efevoopay', 'gateway' => 'efevoopay', 'payment_status' => 'pending']),
        dashboardTransaction(['payment_method' => 'paypal', 'payment_status' => 'completed']),
    ]);

    $metrics = (new AdminDashboardPaymentMethodMetrics)->build(
        collect([
            'laboratory' => collect([$purchase]),
        ]),
        Carbon::parse('2026-09-01', 'America/Monterrey'),
        Carbon::parse('2026-09-01', 'America/Monterrey'),
    );

    expect($metrics['dataPoints'][0]['paypal'])->toBe(1)
        ->and($metrics['dataPoints'][0]['paypalAmountCents'])->toBe(10000)
        ->and($metrics['dataPoints'][0]['efevoopay'])->toBe(0);
});

test('payment methods keep unknown purchases as other', function () {
    $metrics = (new AdminDashboardPaymentMethodMetrics)->build(
        collect([
            'laboratory' => collect([
                dashboardPurchase(1, '2026-09-01 12:00:00', 10000, []),
                dashboardPurchase(2, '2026-09-01 13:00:00', 20000, [
                    dashboardTransaction(['payment_method' => 'stripe', 'payment_status' => 'paid']),
                ]),
            ]),
        ]),
        Carbon::parse('2026-09-01', 'America/Monterrey'),
        Carbon::parse('2026-09-01', 'America/Monterrey'),
    );

    expect($metrics['dataPoints'][0]['other'])->toBe(2)
        ->and($metrics['dataPoints'][0]['otherAmountCents'])->toBe(30000)
        ->and(collect($metrics['series'])->pluck('key'))->toContain('other');
});

test('payment methods calculate daily amount for counted purchases', function () {
    $metrics = (new AdminDashboardPaymentMethodMetrics)->build(
        collect([
            'laboratory' => collect([
                dashboardPurchase(1, '2026-09-01 12:00:00', 12550, [
                    dashboardTransaction(['payment_method' => 'paypal', 'payment_status' => 'completed']),
                ]),
                dashboardPurchase(2, '2026-09-01 13:00:00', 7450, [
                    dashboardTransaction(['payment_method' => 'paypal', 'payment_status' => 'completed']),
                ]),
            ]),
        ]),
        Carbon::parse('2026-09-01', 'America/Monterrey'),
        Carbon::parse('2026-09-01', 'America/Monterrey'),
    );

    expect($metrics['dataPoints'][0]['paypal'])->toBe(2)
        ->and($metrics['dataPoints'][0]['paypalAmountCents'])->toBe(20000)
        ->and($metrics['dataPoints'][0]['paypalFormattedAmount'])->toBe('$200.00 MXN');
});

test('payment methods respect period and monterrey timezone', function () {
    $metrics = (new AdminDashboardPaymentMethodMetrics)->build(
        collect([
            'laboratory' => collect([
                dashboardPurchase(1, '2026-09-01 05:30:00', 10000, [
                    dashboardTransaction(['payment_method' => 'paypal', 'payment_status' => 'completed']),
                ]),
                dashboardPurchase(2, '2026-09-01 06:30:00', 20000, [
                    dashboardTransaction(['payment_method' => 'paypal', 'payment_status' => 'completed']),
                ]),
                dashboardPurchase(3, '2026-09-02 05:30:00', 30000, [
                    dashboardTransaction(['payment_method' => 'efevoopay', 'gateway' => 'efevoopay', 'payment_status' => 'completed']),
                ]),
            ]),
        ]),
        Carbon::parse('2026-09-01', 'America/Monterrey'),
        Carbon::parse('2026-09-01', 'America/Monterrey'),
    );

    expect($metrics['dataPoints'])->toHaveCount(1)
        ->and($metrics['dataPoints'][0]['date'])->toBe('2026-09-01')
        ->and($metrics['dataPoints'][0]['paypal'])->toBe(1)
        ->and($metrics['dataPoints'][0]['paypalAmountCents'])->toBe(20000)
        ->and($metrics['dataPoints'][0]['efevoopay'])->toBe(1)
        ->and($metrics['dataPoints'][0]['efevoopayAmountCents'])->toBe(30000);
});

test('payment methods classify zero total coupon purchases as savings bank when currently counted', function () {
    $metrics = (new AdminDashboardPaymentMethodMetrics)->build(
        collect([
            'laboratory' => collect([
                dashboardPurchase(1, '2026-09-01 12:00:00', 0, [], ['coupon_discount_cents' => 10000]),
            ]),
        ]),
        Carbon::parse('2026-09-01', 'America/Monterrey'),
        Carbon::parse('2026-09-01', 'America/Monterrey'),
    );

    expect($metrics['dataPoints'][0]['savingsBank'])->toBe(1)
        ->and($metrics['dataPoints'][0]['savingsBankAmountCents'])->toBe(0);
});
