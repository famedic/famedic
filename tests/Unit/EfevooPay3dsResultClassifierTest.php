<?php

use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Support\EfevooPay3dsResultClassifier;

it('classifies a generic cancelled provider status without blaming the provider', function () {
    foreach (['cancelled', 'canceled'] as $status) {
        $result = EfevooPay3dsResultClassifier::providerStatus($status);

        expect($result['internal_status'])->toBe(PaymentAuthenticationAttemptStatus::Cancelled)
            ->and($result['result_category'])->toBe(EfevooPay3dsResultClassifier::CATEGORY_CANCELLED)
            ->and($result['failure_origin'])->toBe(EfevooPay3dsResultClassifier::ORIGIN_UNKNOWN)
            ->and($result['failure_certainty'])->toBe(EfevooPay3dsResultClassifier::CERTAINTY_UNKNOWN)
            ->and($result['result_category'])->not->toBe(EfevooPay3dsResultClassifier::CATEGORY_CANCELLED_BY_PROVIDER);
    }
});

it('uses cancelled_by_provider only with explicit evidence', function () {
    $result = EfevooPay3dsResultClassifier::explicitProviderCancellation('C1', 'Cancelled by ACS');

    expect($result['result_category'])->toBe(EfevooPay3dsResultClassifier::CATEGORY_CANCELLED_BY_PROVIDER)
        ->and($result['failure_origin'])->toBe(EfevooPay3dsResultClassifier::ORIGIN_EFEVOOPAY)
        ->and($result['failure_certainty'])->toBe(EfevooPay3dsResultClassifier::CERTAINTY_CONFIRMED);
});

it('classifies a local expiration as challenge expired with unknown origin', function () {
    $result = EfevooPay3dsResultClassifier::localExpiration();

    expect($result['result_category'])->toBe(EfevooPay3dsResultClassifier::CATEGORY_CHALLENGE_EXPIRED)
        ->and($result['failure_origin'])->toBe(EfevooPay3dsResultClassifier::ORIGIN_UNKNOWN)
        ->and($result['failure_certainty'])->toBe(EfevooPay3dsResultClassifier::CERTAINTY_PROBABLE)
        ->and($result['metadata']['detected_by'])->toBe('famedic')
        ->and($result['failure_origin'])->not->toBe(EfevooPay3dsResultClassifier::ORIGIN_FAMEDIC);
});

it('classifies timeouts as network or unknown and never issuer', function () {
    $timeout = EfevooPay3dsResultClassifier::providerLink([
        'success' => false,
        'error_type' => 'timeout',
        'message' => 'timeout after send',
    ]);
    $network = EfevooPay3dsResultClassifier::providerLink([
        'success' => false,
        'error_type' => 'network',
        'message' => 'connection reset',
    ]);

    expect($timeout['result_category'])->toBe(EfevooPay3dsResultClassifier::CATEGORY_PROVIDER_TIMEOUT)
        ->and($timeout['failure_origin'])->toBe(EfevooPay3dsResultClassifier::ORIGIN_NETWORK)
        ->and($timeout['failure_origin'])->not->toBe(EfevooPay3dsResultClassifier::ORIGIN_ISSUER)
        ->and($network['failure_origin'])->toBe(EfevooPay3dsResultClassifier::ORIGIN_NETWORK)
        ->and($network['failure_origin'])->not->toBe(EfevooPay3dsResultClassifier::ORIGIN_ISSUER);
});

it('classifies a generic declined or rejected status as authentication failed with unknown origin', function () {
    foreach (['declined', 'rejected'] as $status) {
        $result = EfevooPay3dsResultClassifier::providerStatus($status, 'R1', 'Rejected by ACS');

        expect($result['internal_status'])->toBe(PaymentAuthenticationAttemptStatus::Declined)
            ->and($result['result_category'])->toBe(EfevooPay3dsResultClassifier::CATEGORY_AUTHENTICATION_FAILED)
            ->and($result['failure_origin'])->toBe(EfevooPay3dsResultClassifier::ORIGIN_UNKNOWN)
            ->and($result['result_category'])->not->toBe(EfevooPay3dsResultClassifier::CATEGORY_ISSUER_DECLINED);
    }
});
