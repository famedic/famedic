<?php

use App\Models\Transaction;
use App\Services\EfevooPayCommissionCalculator;

test('exportCommissionCents usa comisión total calculada para EfevooPay', function () {
    config([
        'efevoopay.commission.rate_percent' => 2.99,
        'efevoopay.commission.vat_rate_percent' => 16,
    ]);

    $transaction = new Transaction([
        'payment_method' => 'efevoopay',
        'gateway' => 'efevoopay',
        'transaction_amount_cents' => 26900,
        'details' => ['commission_cents' => 0],
    ]);

    expect($transaction->exportCommissionCents())->toBe(
        EfevooPayCommissionCalculator::calculate(26900)['total_cents']
    );
});

test('exportCommissionCents no altera comisión almacenada de PayPal', function () {
    $transaction = new Transaction([
        'payment_method' => 'paypal',
        'transaction_amount_cents' => 26900,
        'details' => ['commission_cents' => 450],
    ]);

    expect($transaction->exportCommissionCents())->toBe(450);
});

test('exportCommissionCents no altera comisión almacenada de caja de ahorro', function () {
    $transaction = new Transaction([
        'payment_method' => 'odessa',
        'transaction_amount_cents' => 26900,
        'details' => ['commission_cents' => 0],
    ]);

    expect($transaction->exportCommissionCents())->toBe(0);
});
