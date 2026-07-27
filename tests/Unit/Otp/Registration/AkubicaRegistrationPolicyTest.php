<?php

use App\Services\Otp\Registration\AkubicaRegistrationPolicy;

test('akubica registration policy is off and not patient ready by default', function () {
    expect(AkubicaRegistrationPolicy::isEnabled())->toBeFalse()
        ->and(AkubicaRegistrationPolicy::dependenciesSatisfied())->toBeFalse()
        ->and(AkubicaRegistrationPolicy::canOperate())->toBeFalse()
        ->and(AkubicaRegistrationPolicy::isPatientReady())->toBeFalse()
        ->and(AkubicaRegistrationPolicy::deliveryEnabled())->toBeFalse()
        ->and(AkubicaRegistrationPolicy::ttlMinutes())->toBe(10)
        ->and(AkubicaRegistrationPolicy::purpose())->toBe('akubica_register')
        ->and(AkubicaRegistrationPolicy::codeLength())->toBe(6)
        ->and(AkubicaRegistrationPolicy::maxAttempts())->toBe(5)
        ->and(AkubicaRegistrationPolicy::cooldownSeconds())->toBe(60);
});

test('akubica registration policy canOperate requires all three flags', function () {
    config()->set('otp.p0a.flags.akubica_register_enabled', true);
    config()->set('otp.p0a.flags.infrastructure_enabled', true);
    config()->set('otp.p0a.flags.anti_abuse_enabled', false);

    expect(AkubicaRegistrationPolicy::canOperate())->toBeFalse()
        ->and(AkubicaRegistrationPolicy::isPatientReady())->toBeFalse();

    config()->set('otp.p0a.flags.anti_abuse_enabled', true);
    expect(AkubicaRegistrationPolicy::canOperate())->toBeTrue()
        ->and(AkubicaRegistrationPolicy::isPatientReady())->toBeFalse();
});
