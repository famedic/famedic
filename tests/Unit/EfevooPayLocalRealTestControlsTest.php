<?php

use App\Support\EfevooPayGatewayInspector;
use App\Support\EfevooPayGatewayMode;
use App\Support\EfevooPayLocalRealTestMode;
use App\Support\PaymentAuthenticationEfevooPayAmounts;

beforeEach(function () {
    config([
        'efevoopay.gateway' => 'mock',
        'efevoopay.force_simulation' => false,
        'efevoopay.local_real_tests.enabled' => false,
    ]);
});

it('selects mock gateway explicitly', function () {
    config(['efevoopay.gateway' => 'mock']);

    expect(EfevooPayGatewayMode::current())->toBe('mock')
        ->and(EfevooPayGatewayMode::usesMock())->toBeTrue();
});

it('force simulation overrides live gateway selection', function () {
    config([
        'efevoopay.gateway' => 'live',
        'efevoopay.force_simulation' => true,
    ]);

    expect(EfevooPayGatewayMode::current())->toBe('mock');
});

it('inspector reports mock gateway without external calls', function () {
    $report = EfevooPayGatewayInspector::inspect();

    expect($report['gateway_mode'])->toBe('mock')
        ->and($report['uses_mock'])->toBeTrue()
        ->and($report['api_hostname'])->not->toBeEmpty()
        ->and($report['credentials'])->toHaveKey('all_required_present')
        ->and($report['limits']['getlink_amount_mxn'])->toBe(1.5)
        ->and($report['limits']['tokenize_amount_mxn'])->toBe(1.5)
        ->and($report['limits']['max_verification_total_mxn'])->toBe(3.0)
        ->and($report['limits']['max_payment_mxn'])->toBe(10.0);
});

it('rejects verification totals above local real test limit', function () {
    config([
        'efevoopay.three_ds_verification_amount_cents' => 200,
        'efevoopay.tokenization_verification_amount_cents' => 200,
    ]);

    $validation = EfevooPayLocalRealTestMode::validateCardVerificationAmounts();

    expect($validation['allowed'])->toBeFalse()
        ->and($validation['reason'])->toBe('verification_total_exceeds_limit');
});

it('rejects manipulated payment amounts during local real tests', function () {
    $validation = EfevooPayLocalRealTestMode::validatePaymentAmountCents(1500, 1000);

    expect($validation['allowed'])->toBeFalse()
        ->and($validation['reason'])->toBe('payment_amount_exceeds_limit');
});

it('rejects payment amount mismatch with order total', function () {
    $validation = EfevooPayLocalRealTestMode::validatePaymentAmountCents(500, 1000);

    expect($validation['allowed'])->toBeFalse()
        ->and($validation['reason'])->toBe('payment_amount_mismatch');
});

it('uses integer cent configuration for verification amounts', function () {
    expect(PaymentAuthenticationEfevooPayAmounts::threeDsVerificationAmountCents())->toBe(150)
        ->and(PaymentAuthenticationEfevooPayAmounts::tokenizationVerificationAmountCents())->toBe(150)
        ->and(PaymentAuthenticationEfevooPayAmounts::threeDsVerificationAmount())->toBe(1.5);
});
