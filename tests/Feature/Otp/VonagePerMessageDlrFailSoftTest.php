<?php

use App\Contracts\Otp\OtpDeliveryProvider;
use App\Enums\Otp\VonageSmsDeliveryStatus;
use App\Models\OtpChallenge;
use App\Services\Otp\Delivery\OtpDeliveryOutcome;
use App\Services\Otp\Delivery\OtpDeliveryResult;
use App\Services\Otp\Delivery\OtpDeliveryResultClass;
use App\Services\Otp\Delivery\VonageSmsDeliveryReceiptReconciler;
use App\Services\Otp\Registration\EmailNormalizer;
use App\Services\Otp\Registration\MexicoPhoneNormalizer;
use App\Services\Otp\Registration\RegistrationIdentity;

test('orchestrator fail-soft devuelve succeeded si reconciliacion falla tras aceptacion', function () {
    config([
        'otp.p0a.registration.delivery_enabled' => true,
        'otp.p0a.flags.sms_delivery_enabled' => true,
        'otp.p0a.delivery.driver' => 'fake',
    ]);

    $provider = Mockery::mock(OtpDeliveryProvider::class);
    $provider->shouldReceive('alias')->andReturn('vonage');
    $provider->shouldReceive('send')->andReturn(new OtpDeliveryResult(
        OtpDeliveryResultClass::Accepted,
        '2xx',
        1,
        5,
        'vonage',
        providerMessageId: 'msg-fail-soft-001',
    ));
    app()->instance(OtpDeliveryProvider::class, $provider);

    $reconciler = Mockery::mock(VonageSmsDeliveryReceiptReconciler::class);
    $reconciler->shouldReceive('reconcilePendingForMessageId')->andThrow(new RuntimeException('reconcile failed'));
    app()->instance(VonageSmsDeliveryReceiptReconciler::class, $reconciler);

    $challenge = OtpChallenge::factory()->create([
        'purpose' => 'akubica_register',
        'destination_normalized' => '+525512345678',
    ]);

    $identity = new RegistrationIdentity(
        email: app(EmailNormalizer::class)->normalize('test@example.com'),
        phone: app(MexicoPhoneNormalizer::class)->normalize('5512345678', 'MX'),
        fullName: 'Test Usuario',
    );

    $outcome = app(\App\Services\Otp\Delivery\AkubicaSecureOtpDeliveryOrchestrator::class)
        ->deliverRegisterSafely($challenge, '123456', $identity, (string) \Illuminate\Support\Str::uuid());

    expect($outcome)->toBe(OtpDeliveryOutcome::Succeeded);

    $operation = \App\Models\OtpDeliveryOperation::query()->where('otp_challenge_id', $challenge->id)->first();
    expect($operation)->not->toBeNull();
    expect($operation->sms_delivery_status)->toBe(VonageSmsDeliveryStatus::Accepted->value);
    expect($operation->provider_message_id)->toBe('msg-fail-soft-001');
});
