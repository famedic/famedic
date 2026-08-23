<?php

use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Enums\PaymentAuthenticationRecoveryContextType;
use App\Support\PaymentAuthenticationRecoveryContextDataNormalizer;
use App\Support\PaymentAuthenticationRecoveryLegacyReturnUrlParser;
use App\Support\PaymentAuthenticationRecoveryReturnRouteAllowlist;

test('context types are a closed catalog', function () {
    expect(PaymentAuthenticationRecoveryContextType::values())->toBe([
        'payment_method_settings',
        'laboratory_checkout',
        'medical_attention_checkout',
        'medical_attention_modal',
        'online_pharmacy_checkout',
    ]);
});

test('origin query is mapped by the backend and arbitrary values are rejected', function () {
    expect(PaymentAuthenticationRecoveryContextType::fromOrigin('laboratory'))
        ->toBe(PaymentAuthenticationRecoveryContextType::LaboratoryCheckout)
        ->and(PaymentAuthenticationRecoveryContextType::fromOrigin('laboratory_checkout'))
        ->toBeNull()
        ->and(PaymentAuthenticationRecoveryContextType::fromOrigin('evil'))
        ->toBeNull();
});

test('paypal is allowed only for laboratory and membership checkout', function () {
    expect(PaymentAuthenticationRecoveryContextType::LaboratoryCheckout->supportsPayPal())->toBeTrue()
        ->and(PaymentAuthenticationRecoveryContextType::MedicalAttentionCheckout->supportsPayPal())->toBeTrue()
        ->and(PaymentAuthenticationRecoveryContextType::MedicalAttentionModal->supportsPayPal())->toBeFalse()
        ->and(PaymentAuthenticationRecoveryContextType::PaymentMethodSettings->supportsPayPal())->toBeFalse()
        ->and(PaymentAuthenticationRecoveryContextType::OnlinePharmacyCheckout->supportsPayPal())->toBeFalse();
});

test('recovery context transitions are centralized', function () {
    $open = PaymentAuthenticationRecoveryContextStatus::Open;

    expect($open->canTransitionTo(PaymentAuthenticationRecoveryContextStatus::AuthenticationInProgress))->toBeTrue()
        ->and($open->canTransitionTo(PaymentAuthenticationRecoveryContextStatus::Recovered))->toBeFalse()
        ->and(PaymentAuthenticationRecoveryContextStatus::AuthenticationInProgress->canTransitionTo(PaymentAuthenticationRecoveryContextStatus::CardVerified))->toBeTrue()
        ->and(PaymentAuthenticationRecoveryContextStatus::AuthenticationInProgress->canTransitionTo(PaymentAuthenticationRecoveryContextStatus::Recovered))->toBeFalse()
        ->and(PaymentAuthenticationRecoveryContextStatus::CardVerified->canTransitionTo(PaymentAuthenticationRecoveryContextStatus::Recovered))->toBeTrue()
        ->and(PaymentAuthenticationRecoveryContextStatus::Expired->canTransitionTo(PaymentAuthenticationRecoveryContextStatus::Open))->toBeFalse();
});

test('return route allowlist is closed', function () {
    expect(PaymentAuthenticationRecoveryReturnRouteAllowlist::isAllowed('laboratory.checkout'))->toBeTrue()
        ->and(PaymentAuthenticationRecoveryReturnRouteAllowlist::isAllowed('admin.customers.index'))->toBeFalse()
        ->and(PaymentAuthenticationRecoveryReturnRouteAllowlist::isAllowed('https://evil.example'))->toBeFalse();
});

test('legacy parser rejects external javascript and non allowlisted paths', function () {
    $parser = new PaymentAuthenticationRecoveryLegacyReturnUrlParser;

    expect($parser->isSafe('https://evil.example/phish'))->toBeFalse()
        ->and($parser->isSafe('javascript:alert(1)'))->toBeFalse()
        ->and($parser->isSafe('/admin'))->toBeFalse()
        ->and($parser->isSafe(route('payment-methods.index', [], false)))->toBeTrue()
        ->and($parser->isSafe(route('laboratory.checkout', ['laboratory_brand' => 'olab'], false)))->toBeTrue()
        ->and($parser->isSafe(route('medical-attention.checkout', [], false)))->toBeTrue()
        ->and($parser->isSafe(route('online-pharmacy.checkout', [], false)))->toBeTrue();
});

test('context data denylist includes pan cvv promo token and return url', function () {
    expect(PaymentAuthenticationRecoveryContextDataNormalizer::DENYLIST)->toContain(
        'card_number',
        'cvv',
        'promo_validation_token',
        'payment_method',
        'return_url',
        'client_token',
    );
});
