<?php

use App\Contracts\Otp\OtpDeliveryProvider;
use App\Services\Otp\Delivery\AkubicaSecureOtpDeliveryOrchestrator;
use App\Services\Otp\Delivery\FakeOtpDeliveryProvider;
use App\Services\Otp\Delivery\OtpDeliveryResultClass;

/**
 * P0-T1 — Explicit Akubica OTP config helpers for Pest/PHPUnit.
 *
 * Defaults live in phpunit.xml (all feature flags OFF, delivery driver=fake).
 * Suites that exercise P0-A/P0-B must call an enable* helper (or equivalent config()->set).
 */

/**
 * @return array<string, string>
 */
function akubicaOtpPhpunitBaselineEnv(): array
{
    return [
        'OTP_P0A_INFRASTRUCTURE_ENABLED' => 'false',
        'OTP_P0A_AKUBICA_REGISTER_ENABLED' => 'false',
        'OTP_P0A_AKUBICA_LOGIN_ENABLED' => 'false',
        'OTP_P0A_SMS_DELIVERY_ENABLED' => 'false',
        'OTP_P0A_EMAIL_FALLBACK_ENABLED' => 'false',
        'OTP_P0A_ANTI_ABUSE_ENABLED' => 'false',
        'OTP_P0A_STEP_UP_RESULTS_ENABLED' => 'false',
        'OTP_P0A_STEP_UP_INVOICES_ENABLED' => 'false',
        'OTP_P0A_SECURE_LINKS_RESULTS_ENABLED' => 'false',
        'OTP_P0A_SECURE_LINKS_INVOICES_ENABLED' => 'false',
        'OTP_P0A_STEP_UP_BEARER_DOWNLOADS_ENABLED' => 'false',
        'OTP_P0A_SANCTUM_3H_ENABLED' => 'false',
        'OTP_P0A_DELIVERY_DRIVER' => 'fake',
    ];
}

function refreshAkubicaOtpDeliveryBinding(): void
{
    app()->forgetInstance(OtpDeliveryProvider::class);
    app()->forgetInstance(AkubicaSecureOtpDeliveryOrchestrator::class);
}

function enableRegisterOtpWithoutDelivery(): void
{
    config()->set('otp.p0a.flags.akubica_register_enabled', true);
    config()->set('otp.p0a.flags.infrastructure_enabled', true);
    config()->set('otp.p0a.flags.anti_abuse_enabled', true);
    config()->set('otp.p0a.flags.sms_delivery_enabled', false);
    config()->set('otp.p0a.registration.delivery_enabled', false);
    config()->set('otp.p0a.flags.email_fallback_enabled', false);
    config()->set('otp.p0a.flags.akubica_login_enabled', false);
    config()->set('otp.p0a.delivery.driver', 'fake');
    refreshAkubicaOtpDeliveryBinding();
}

function enableRegisterOtpWithFakeDelivery(): void
{
    enableRegisterOtpWithoutDelivery();
    config()->set('otp.p0a.flags.sms_delivery_enabled', true);
    config()->set('otp.p0a.registration.delivery_enabled', true);
    config()->set('otp.p0a.delivery.driver', 'fake');
    refreshAkubicaOtpDeliveryBinding();
    app(FakeOtpDeliveryProvider::class)->alwaysAccept();
    app(FakeOtpDeliveryProvider::class)->sent = [];
}

function disableAllAkubicaOtpFeatures(): void
{
    config()->set('otp.p0a.flags.infrastructure_enabled', false);
    config()->set('otp.p0a.flags.akubica_register_enabled', false);
    config()->set('otp.p0a.flags.akubica_login_enabled', false);
    config()->set('otp.p0a.flags.sms_delivery_enabled', false);
    config()->set('otp.p0a.registration.delivery_enabled', false);
    config()->set('otp.p0a.flags.email_fallback_enabled', false);
    config()->set('otp.p0a.flags.anti_abuse_enabled', false);
    config()->set('otp.p0a.flags.step_up_results_enabled', false);
    config()->set('otp.p0a.flags.step_up_invoices_enabled', false);
    config()->set('otp.p0a.flags.secure_links_results_enabled', false);
    config()->set('otp.p0a.flags.secure_links_invoices_enabled', false);
    config()->set('otp.p0a.flags.step_up_bearer_downloads_enabled', false);
    config()->set('otp.p0a.flags.sanctum_3h_enabled', false);
    config()->set('otp.p0a.delivery.driver', 'fake');
    config()->set('otp.p0a.secure_links.ttl_minutes', 60);
    config()->set('otp.p0a.secure_links.max_opens', 5);
    refreshAkubicaOtpDeliveryBinding();
}

function enableLoginOtpWithFakeDelivery(): void
{
    config()->set('otp.p0a.flags.akubica_login_enabled', true);
    config()->set('otp.p0a.flags.anti_abuse_enabled', true);
    config()->set('otp.p0a.flags.sms_delivery_enabled', true);
    config()->set('otp.p0a.flags.email_fallback_enabled', false);
    config()->set('otp.p0a.flags.akubica_register_enabled', false);
    config()->set('otp.p0a.delivery.driver', 'fake');
    config()->set('otp.p0a.policy.require_verified_phone', true);
    refreshAkubicaOtpDeliveryBinding();
    app(FakeOtpDeliveryProvider::class)->alwaysAccept();
    app(FakeOtpDeliveryProvider::class)->sent = [];
}

function enableResultsStepUpWithFakeDelivery(): void
{
    config()->set('otp.p0a.flags.step_up_results_enabled', true);
    config()->set('otp.p0a.flags.anti_abuse_enabled', true);
    config()->set('otp.p0a.flags.sms_delivery_enabled', true);
    config()->set('otp.p0a.flags.email_fallback_enabled', false);
    config()->set('otp.p0a.flags.akubica_login_enabled', false);
    config()->set('otp.p0a.flags.akubica_register_enabled', false);
    config()->set('otp.p0a.delivery.driver', 'fake');
    config()->set('otp.p0a.policy.require_verified_phone', true);
    config()->set('otp.p0a.step_up.grant_ttl_minutes', 10);
    config()->set('otp.p0a.step_up.bind_to_sanctum_token', true);
    config()->set('otp.p0a.step_up.bind_to_purpose', true);
    config()->set('otp.p0a.step_up.bind_to_resource', true);
    refreshAkubicaOtpDeliveryBinding();
    app(FakeOtpDeliveryProvider::class)->alwaysAccept();
    app(FakeOtpDeliveryProvider::class)->sent = [];
}

function enableResultsSecureLinks(): void
{
    enableResultsStepUpWithFakeDelivery();
    config()->set('otp.p0a.flags.secure_links_results_enabled', true);
    config()->set('otp.p0a.secure_links.ttl_minutes', 5);
    config()->set('otp.p0a.secure_links.max_opens', 1);
}

function enableInvoiceStepUpWithFakeDelivery(): void
{
    config()->set('otp.p0a.flags.step_up_invoices_enabled', true);
    config()->set('otp.p0a.flags.anti_abuse_enabled', true);
    config()->set('otp.p0a.flags.sms_delivery_enabled', true);
    config()->set('otp.p0a.flags.email_fallback_enabled', false);
    config()->set('otp.p0a.flags.akubica_login_enabled', false);
    config()->set('otp.p0a.flags.akubica_register_enabled', false);
    config()->set('otp.p0a.delivery.driver', 'fake');
    config()->set('otp.p0a.policy.require_verified_phone', true);
    config()->set('otp.p0a.policy.max_attempts', 5);
    config()->set('otp.p0a.policy.ttl_minutes', 5);
    config()->set('otp.p0a.policy.cooldown_seconds', 60);
    config()->set('otp.p0a.step_up.bind_to_sanctum_token', true);
    refreshAkubicaOtpDeliveryBinding();
    app(FakeOtpDeliveryProvider::class)->alwaysAccept();
    app(FakeOtpDeliveryProvider::class)->sent = [];
}

function enableInvoiceSecureLinks(): void
{
    enableInvoiceStepUpWithFakeDelivery();
    config()->set('otp.p0a.flags.secure_links_invoices_enabled', true);
    config()->set('otp.p0a.flags.secure_links_results_enabled', false);
    config()->set('otp.p0a.secure_links.ttl_minutes', 5);
    config()->set('otp.p0a.secure_links.max_opens', 1);
}

function useFailingOtpDelivery(OtpDeliveryResultClass $result = OtpDeliveryResultClass::TransportError): void
{
    config()->set('otp.p0a.delivery.driver', 'fake');
    refreshAkubicaOtpDeliveryBinding();
    app(FakeOtpDeliveryProvider::class)->failAlwaysWith($result);
}
