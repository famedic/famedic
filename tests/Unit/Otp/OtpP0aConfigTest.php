<?php

/**
 * Helpers to reload otp.php after mutating process env (tests only).
 *
 * @param  array<string, string|null>  $overrides
 */
function otpP0aReloadConfig(array $overrides = []): void
{
    $keys = [
        'OTP_P0A_INFRASTRUCTURE_ENABLED',
        'OTP_P0A_SMS_DELIVERY_ENABLED',
        'OTP_P0A_EMAIL_FALLBACK_ENABLED',
        'OTP_P0A_ANTI_ABUSE_ENABLED',
        'OTP_P0A_AKUBICA_LOGIN_ENABLED',
        'OTP_P0A_AKUBICA_REGISTER_ENABLED',
        'OTP_P0A_SANCTUM_3H_ENABLED',
        'OTP_P0A_STEP_UP_RESULTS_ENABLED',
        'OTP_P0A_STEP_UP_INVOICES_ENABLED',
        'OTP_P0A_STEP_UP_BEARER_DOWNLOADS_ENABLED',
        'OTP_P0A_SECURE_LINKS_RESULTS_ENABLED',
        'OTP_P0A_SECURE_LINKS_INVOICES_ENABLED',
        'OTP_P0A_DELIVERY_DRIVER',
        'OTP_P0A_SMS_DELIVERY_PROVIDER',
        'OTP_P0A_TTL_MINUTES',
        'OTP_P0A_REGISTER_TTL_MINUTES',
        'OTP_P0A_LENGTH',
        'OTP_P0A_MAX_ATTEMPTS',
        'OTP_P0A_COOLDOWN_SECONDS',
        'OTP_P0A_RESEND_WINDOW_MINUTES',
        'OTP_P0A_MAX_RESENDS',
        'OTP_P0A_BLOCK_MINUTES',
        'OTP_P0A_REQUIRE_VERIFIED_PHONE',
        'OTP_P0A_PRIMARY_CHANNEL',
        'OTP_P0A_FALLBACK_MODE',
        'OTP_P0A_AUDIT_ENABLED',
        'OTP_P0A_IDENTITY_MAX_REQUESTS',
        'OTP_P0A_IP_MAX_REQUESTS',
        'OTP_P0A_RATE_LIMIT_WINDOW_MINUTES',
        'OTP_P0A_ABUSE_RETENTION_DAYS',
        'OTP_P0A_STEP_UP_GRANT_TTL_MINUTES',
        'OTP_P0A_STEP_UP_BIND_SANCTUM_TOKEN',
        'OTP_P0A_STEP_UP_BIND_PURPOSE',
        'OTP_P0A_STEP_UP_BIND_RESOURCE',
        'OTP_P0A_SANCTUM_TARGET_EXPIRATION_MINUTES',
        'OTP_P0A_SECURE_LINK_TTL_MINUTES',
        'OTP_P0A_SECURE_LINK_MAX_OPENS',
        'SANCTUM_TOKEN_EXPIRATION',
        'AKUBICA_OTP_TTL_MINUTES',
        'AKUBICA_TOKEN_TTL_MINUTES',
        'AKUBICA_TOKEN_TTL_MINUTES_P0A_TARGET',
    ];

    foreach ($keys as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    foreach ($overrides as $key => $value) {
        if ($value === null) {
            continue;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    if (function_exists('putenv')) {
        // Ensure Laravel env repository picks up putenv changes when possible.
    }

    config()->set('otp', require config_path('otp.php'));
    config()->set('akubica', require config_path('akubica.php'));
}

beforeEach(function () {
    // Clear OTP env so assertions exercise otp.php file defaults (not phpunit.xml).
    otpP0aReloadConfig();
});

afterEach(function () {
    // Restore PHPUnit testing baseline so later suites are not contaminated.
    otpP0aReloadConfig(akubicaOtpPhpunitBaselineEnv());
});

test('p0a breaking feature flags are disabled by default', function () {
    expect(config('otp.p0a.flags.infrastructure_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.sms_delivery_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.email_fallback_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.anti_abuse_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.akubica_login_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.akubica_register_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.sanctum_3h_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.step_up_results_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.step_up_invoices_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.step_up_bearer_downloads_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.secure_links_results_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.secure_links_invoices_enabled'))->toBeFalse();
});

test('p0a target policy values are available with approved defaults', function () {
    expect(config('otp.p0a.policy.ttl_minutes'))->toBe(5)
        ->and(config('otp.p0a.policy.length'))->toBe(6)
        ->and(config('otp.p0a.policy.max_attempts'))->toBe(5)
        ->and(config('otp.p0a.policy.cooldown_seconds'))->toBe(60)
        ->and(config('otp.p0a.policy.resend_window_minutes'))->toBe(30)
        ->and(config('otp.p0a.policy.max_resends'))->toBe(3)
        ->and(config('otp.p0a.policy.block_minutes'))->toBe(30)
        ->and(config('otp.p0a.policy.require_verified_phone'))->toBeTrue()
        ->and(config('otp.p0a.policy.primary_channel'))->toBe('sms')
        ->and(config('otp.p0a.policy.fallback_mode'))->toBe('on_sms_failure')
        ->and(config('otp.p0a.policy.audit_enabled'))->toBeTrue();
});

test('boolean env flags are interpreted correctly', function () {
    otpP0aReloadConfig([
        'OTP_P0A_INFRASTRUCTURE_ENABLED' => 'true',
        'OTP_P0A_SMS_DELIVERY_ENABLED' => '1',
        'OTP_P0A_ANTI_ABUSE_ENABLED' => 'yes',
        'OTP_P0A_STEP_UP_RESULTS_ENABLED' => 'false',
        'OTP_P0A_REQUIRE_VERIFIED_PHONE' => '0',
    ]);

    expect(config('otp.p0a.flags.infrastructure_enabled'))->toBeTrue()
        ->and(config('otp.p0a.flags.sms_delivery_enabled'))->toBeTrue()
        ->and(config('otp.p0a.flags.anti_abuse_enabled'))->toBeTrue()
        ->and(config('otp.p0a.flags.step_up_results_enabled'))->toBeFalse()
        ->and(config('otp.p0a.policy.require_verified_phone'))->toBeFalse();
});

test('numeric env values are cast correctly', function () {
    otpP0aReloadConfig([
        'OTP_P0A_TTL_MINUTES' => '7',
        'OTP_P0A_COOLDOWN_SECONDS' => '90',
        'OTP_P0A_MAX_RESENDS' => '2',
        'OTP_P0A_STEP_UP_GRANT_TTL_MINUTES' => '15',
    ]);

    expect(config('otp.p0a.policy.ttl_minutes'))->toBe(7)
        ->and(config('otp.p0a.policy.cooldown_seconds'))->toBe(90)
        ->and(config('otp.p0a.policy.max_resends'))->toBe(2)
        ->and(config('otp.p0a.step_up.grant_ttl_minutes'))->toBe(15);
});

test('invalid numeric values fall back to safe defaults', function () {
    otpP0aReloadConfig([
        'OTP_P0A_TTL_MINUTES' => '0',
        'OTP_P0A_MAX_ATTEMPTS' => '-3',
        'OTP_P0A_LENGTH' => 'not-a-number',
        'OTP_P0A_BLOCK_MINUTES' => '99999',
    ]);

    expect(config('otp.p0a.policy.ttl_minutes'))->toBe(5)
        ->and(config('otp.p0a.policy.max_attempts'))->toBe(5)
        ->and(config('otp.p0a.policy.length'))->toBe(6)
        ->and(config('otp.p0a.policy.block_minutes'))->toBe(30);
});

test('fallback mode only accepts known values', function () {
    otpP0aReloadConfig(['OTP_P0A_FALLBACK_MODE' => 'never']);
    expect(config('otp.p0a.policy.fallback_mode'))->toBe('never');

    otpP0aReloadConfig(['OTP_P0A_FALLBACK_MODE' => 'user_authorized']);
    expect(config('otp.p0a.policy.fallback_mode'))->toBe('user_authorized');

    otpP0aReloadConfig(['OTP_P0A_FALLBACK_MODE' => 'on_sms_failure']);
    expect(config('otp.p0a.policy.fallback_mode'))->toBe('on_sms_failure');

    otpP0aReloadConfig(['OTP_P0A_FALLBACK_MODE' => 'always_both']);
    expect(config('otp.p0a.policy.fallback_mode'))->toBe('on_sms_failure');
});

test('primary channel only accepts sms or email', function () {
    otpP0aReloadConfig(['OTP_P0A_PRIMARY_CHANNEL' => 'email']);
    expect(config('otp.p0a.policy.primary_channel'))->toBe('email');

    otpP0aReloadConfig(['OTP_P0A_PRIMARY_CHANNEL' => 'sms']);
    expect(config('otp.p0a.policy.primary_channel'))->toBe('sms');

    otpP0aReloadConfig(['OTP_P0A_PRIMARY_CHANNEL' => 'whatsapp']);
    expect(config('otp.p0a.policy.primary_channel'))->toBe('sms');
});

test('sanctum keeps 1440 minutes effective while p0a target is 180', function () {
    otpP0aReloadConfig();

    expect((int) config('sanctum.expiration'))->toBe(1440)
        ->and(config('otp.p0a.sanctum.target_expiration_minutes'))->toBe(180)
        ->and(config('otp.p0a.flags.sanctum_3h_enabled'))->toBeFalse()
        ->and(config('akubica.token_ttl_minutes'))->toBe(1440)
        ->and(config('akubica.token_ttl_minutes_p0a_target'))->toBe(180);
});

test('step-up grant ttl defaults to 10 minutes without enabling step-up', function () {
    expect(config('otp.p0a.step_up.grant_ttl_minutes'))->toBe(10)
        ->and(config('otp.p0a.step_up.bind_to_sanctum_token'))->toBeTrue()
        ->and(config('otp.p0a.step_up.bind_to_purpose'))->toBeTrue()
        ->and(config('otp.p0a.step_up.bind_to_resource'))->toBeTrue()
        ->and(config('otp.p0a.flags.step_up_results_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.step_up_invoices_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.step_up_bearer_downloads_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.secure_links_results_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.secure_links_invoices_enabled'))->toBeFalse();
});

test('legacy otp and akubica defaults remain unchanged for current flows', function () {
    expect(config('otp.digits'))->toBe(6)
        ->and(config('otp.expiry'))->toBe(10)
        ->and(config('akubica.otp_ttl_minutes'))->toBe(10)
        ->and(config('akubica.otp_length'))->toBe(6)
        ->and(config('akubica.otp_max_attempts'))->toBe(5);
});

test('secure link contract defaults are prepared for DEC-007', function () {
    expect(config('otp.p0a.secure_links.ttl_minutes'))->toBe(60)
        ->and(config('otp.p0a.secure_links.max_opens'))->toBe(5)
        ->and(config('otp.p0a.flags.secure_links_results_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.secure_links_invoices_enabled'))->toBeFalse();
});

test('p0a3 anti-abuse config defaults are safe and flag stays off', function () {
    expect(config('otp.p0a.flags.anti_abuse_enabled'))->toBeFalse()
        ->and(config('otp.p0a.anti_abuse.identity_max_requests'))->toBe(4)
        ->and(config('otp.p0a.anti_abuse.ip_max_requests'))->toBe(20)
        ->and(config('otp.p0a.anti_abuse.rate_limit_window_minutes'))->toBe(30)
        ->and(config('otp.p0a.anti_abuse.retention_days'))->toBe(30)
        ->and(config('otp.p0a.policy.cooldown_seconds'))->toBe(60)
        ->and(config('otp.p0a.policy.block_minutes'))->toBe(30)
        ->and(config('otp.p0a.policy.max_resends'))->toBe(3);
});

test('p0a3 anti-abuse invalid env values fall back to safe defaults', function () {
    otpP0aReloadConfig([
        'OTP_P0A_IDENTITY_MAX_REQUESTS' => '0',
        'OTP_P0A_IP_MAX_REQUESTS' => 'not-a-number',
        'OTP_P0A_RATE_LIMIT_WINDOW_MINUTES' => '99999',
        'OTP_P0A_ABUSE_RETENTION_DAYS' => '-5',
    ]);

    expect(config('otp.p0a.anti_abuse.identity_max_requests'))->toBe(4)
        ->and(config('otp.p0a.anti_abuse.ip_max_requests'))->toBe(20)
        ->and(config('otp.p0a.anti_abuse.rate_limit_window_minutes'))->toBe(30)
        ->and(config('otp.p0a.anti_abuse.retention_days'))->toBe(30);
});

test('p0a4 akubica login flag is disabled by default', function () {
    expect(config('otp.p0a.flags.akubica_login_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.anti_abuse_enabled'))->toBeFalse();
});

test('p0a5 akubica register flag and policy defaults are safe', function () {
    expect(config('otp.p0a.flags.akubica_register_enabled'))->toBeFalse()
        ->and(config('otp.p0a.registration.purpose'))->toBe('akubica_register')
        ->and(config('otp.p0a.registration.ttl_minutes'))->toBe(10)
        ->and(config('otp.p0a.registration.length'))->toBe(6)
        ->and(config('otp.p0a.registration.max_attempts'))->toBe(5)
        ->and(config('otp.p0a.registration.cooldown_seconds'))->toBe(60)
        ->and(config('otp.p0a.registration.max_resends'))->toBe(3)
        ->and(config('otp.p0a.registration.delivery_enabled'))->toBeFalse()
        ->and(config('otp.p0a.registration.requires_infrastructure'))->toBeTrue()
        ->and(config('otp.p0a.registration.requires_anti_abuse'))->toBeTrue()
        ->and(config('otp.p0a.policy.ttl_minutes'))->toBe(5)
        ->and(config('otp.p0a.sanctum.current_expiration_minutes'))->toBe(1440)
        ->and(config('sanctum.expiration'))->toBe(1440);
});

test('p0a5 register ttl env override does not change login policy ttl', function () {
    otpP0aReloadConfig([
        'OTP_P0A_REGISTER_TTL_MINUTES' => '12',
        'OTP_P0A_TTL_MINUTES' => '5',
    ]);

    expect(config('otp.p0a.registration.ttl_minutes'))->toBe(12)
        ->and(config('otp.p0a.policy.ttl_minutes'))->toBe(5);
});

test('p0t1 delivery driver env selects provider and obsolete provider env is ignored', function () {
    otpP0aReloadConfig([
        'OTP_P0A_DELIVERY_DRIVER' => 'fake',
        'OTP_P0A_SMS_DELIVERY_PROVIDER' => 'vonage',
    ]);

    expect(config('otp.p0a.delivery.driver'))->toBe('fake')
        ->and(config('otp.p0a.sms_delivery.provider'))->toBeNull();

    refreshAkubicaOtpDeliveryBinding();
    expect(app(\App\Contracts\Otp\OtpDeliveryProvider::class))
        ->toBeInstanceOf(\App\Services\Otp\Delivery\FakeOtpDeliveryProvider::class)
        ->and(app(\App\Contracts\Otp\OtpDeliveryProvider::class)->alias())->toBe('fake');

    otpP0aReloadConfig([
        'OTP_P0A_DELIVERY_DRIVER' => 'null',
        'OTP_P0A_SMS_DELIVERY_PROVIDER' => 'vonage',
    ]);
    refreshAkubicaOtpDeliveryBinding();

    // Laravel Env casts the string "null" to PHP null; binding still resolves Null provider.
    expect(config('otp.p0a.delivery.driver'))->toBeNull()
        ->and(app(\App\Contracts\Otp\OtpDeliveryProvider::class))
        ->toBeInstanceOf(\App\Services\Otp\Delivery\NullOtpDeliveryProvider::class)
        ->and(app(\App\Contracts\Otp\OtpDeliveryProvider::class)->alias())->toBe('null');
});

test('p0t1 otp.php file default for delivery driver remains null when env cleared', function () {
    otpP0aReloadConfig();

    expect(config('otp.p0a.delivery.driver'))->toBe('null');
});
