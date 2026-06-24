<?php

use App\Services\EfevooPayCommissionCalculator;

test('calcula comisión EfevooPay como porcentaje + IVA sobre la comisión', function () {
    config([
        'efevoopay.commission.rate_percent' => 2.9,
        'efevoopay.commission.vat_rate_percent' => 16,
    ]);

    $calc269 = EfevooPayCommissionCalculator::calculate(26900);
    expect($calc269['base_cents'])->toBe(780);
    expect($calc269['vat_cents'])->toBe(124);
    expect($calc269['total_cents'])->toBe(904);
});

test('ejemplos EfevooPay coinciden con tasa efectiva 2.99%', function () {
    config([
        'efevoopay.commission.rate_percent' => 2.99,
        'efevoopay.commission.vat_rate_percent' => 16,
    ]);

    $calc269 = EfevooPayCommissionCalculator::calculate(26900);
    expect($calc269['base_cents'])->toBe(804);
    expect($calc269['vat_cents'])->toBe(128);
    expect($calc269['total_cents'])->toBe(932);

    $calc206429 = EfevooPayCommissionCalculator::calculate(206429);
    expect($calc206429['base_cents'])->toBe(6172);
    expect($calc206429['vat_cents'])->toBe(987);
    expect($calc206429['total_cents'])->toBe(7159);
});

test('present formatea comisión EfevooPay', function () {
    config([
        'efevoopay.commission.rate_percent' => 2.9,
        'efevoopay.commission.vat_rate_percent' => 16,
    ]);

    $present = EfevooPayCommissionCalculator::present(26900);

    expect($present)->toHaveKeys([
        'formatted_base',
        'formatted_vat',
        'formatted_total',
        'rate_percent',
        'vat_rate_percent',
    ]);
});
