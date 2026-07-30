<?php

use App\Contracts\Otp\OtpDeliveryProvider;
use App\Services\Otp\Delivery\FakeOtpDeliveryProvider;
use App\Services\Otp\Delivery\NullOtpDeliveryProvider;
use App\Services\Otp\Delivery\VonageOtpDeliveryProvider;

/**
 * P0-T1 — Prove PHPUnit baseline isolates Akubica OTP from personal .env.
 */

test('p0t1 phpunit baseline keeps legacy auth flags off and delivery driver fake', function () {
    expect(config('app.env'))->toBe('testing')
        ->and(config('otp.p0a.flags.infrastructure_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.akubica_register_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.akubica_login_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.sms_delivery_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.anti_abuse_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.step_up_results_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.step_up_invoices_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.secure_links_results_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.secure_links_invoices_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.step_up_bearer_downloads_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.sanctum_3h_enabled'))->toBeFalse()
        ->and(config('otp.p0a.delivery.driver'))->toBe('fake')
        ->and(config('otp.p0a.secure_links.ttl_minutes'))->toBe(60)
        ->and(config('otp.p0a.secure_links.max_opens'))->toBe(5);

    refreshAkubicaOtpDeliveryBinding();
    $provider = app(OtpDeliveryProvider::class);

    expect($provider)->toBeInstanceOf(FakeOtpDeliveryProvider::class)
        ->and($provider)->not->toBeInstanceOf(VonageOtpDeliveryProvider::class)
        ->and($provider->alias())->toBe('fake');
});

test('p0t1 enabling login otp does not leave flags on for the next test baseline', function () {
    enableLoginOtpWithFakeDelivery();

    expect(config('otp.p0a.flags.akubica_login_enabled'))->toBeTrue()
        ->and(config('otp.p0a.flags.sms_delivery_enabled'))->toBeTrue()
        ->and(config('otp.p0a.delivery.driver'))->toBe('fake');

    // Simulate end-of-test cleanup used by suites; Pest V1 beforeEach will also reset.
    disableAllAkubicaOtpFeatures();

    expect(config('otp.p0a.flags.akubica_login_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.sms_delivery_enabled'))->toBeFalse()
        ->and(config('otp.p0a.delivery.driver'))->toBe('fake');
});

test('p0t1 next test after enable helper still starts from disabled baseline', function () {
    expect(config('otp.p0a.flags.akubica_login_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.akubica_register_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.step_up_results_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.step_up_invoices_enabled'))->toBeFalse()
        ->and(config('otp.p0a.delivery.driver'))->toBe('fake');
});

test('p0t1 obsolete sms delivery provider env never selects vonage binding', function () {
    // Even if a suite mutates process env with the obsolete key, driver remains the selector.
    putenv('OTP_P0A_SMS_DELIVERY_PROVIDER=vonage');
    $_ENV['OTP_P0A_SMS_DELIVERY_PROVIDER'] = 'vonage';
    $_SERVER['OTP_P0A_SMS_DELIVERY_PROVIDER'] = 'vonage';

    config()->set('otp.p0a.delivery.driver', 'fake');
    refreshAkubicaOtpDeliveryBinding();

    expect(app(OtpDeliveryProvider::class))->toBeInstanceOf(FakeOtpDeliveryProvider::class)
        ->and(app(OtpDeliveryProvider::class))->not->toBeInstanceOf(VonageOtpDeliveryProvider::class);

    putenv('OTP_P0A_SMS_DELIVERY_PROVIDER');
    unset($_ENV['OTP_P0A_SMS_DELIVERY_PROVIDER'], $_SERVER['OTP_P0A_SMS_DELIVERY_PROVIDER']);
});

test('p0t1 switching delivery driver rebinds provider without vonage', function () {
    config()->set('otp.p0a.delivery.driver', 'null');
    refreshAkubicaOtpDeliveryBinding();
    expect(app(OtpDeliveryProvider::class))->toBeInstanceOf(NullOtpDeliveryProvider::class);

    config()->set('otp.p0a.delivery.driver', 'fake');
    refreshAkubicaOtpDeliveryBinding();
    expect(app(OtpDeliveryProvider::class))->toBeInstanceOf(FakeOtpDeliveryProvider::class)
        ->and(app(OtpDeliveryProvider::class))->not->toBeInstanceOf(VonageOtpDeliveryProvider::class);
});

test('p0t1 secure link ttl overrides are suite-local and baseline restores defaults', function () {
    enableResultsSecureLinks();
    expect(config('otp.p0a.secure_links.ttl_minutes'))->toBe(5)
        ->and(config('otp.p0a.secure_links.max_opens'))->toBe(1);

    disableAllAkubicaOtpFeatures();

    expect(config('otp.p0a.secure_links.ttl_minutes'))->toBe(60)
        ->and(config('otp.p0a.secure_links.max_opens'))->toBe(5);
});
