<?php

use App\Contracts\Otp\OtpCodeGenerator;
use App\Contracts\Otp\OtpDeliveryProvider;
use App\Contracts\Otp\VonageSmsSendGateway;
use App\Models\OtpDeliveryOperation;
use App\Services\Otp\Delivery\AkubicaSecureOtpDeliveryOrchestrator;
use App\Services\Otp\Delivery\OtpDeliveryOutcome;
use App\Services\Otp\Delivery\OtpDeliveryResult;
use App\Services\Otp\Delivery\OtpDeliveryResultClass;
use App\Services\Otp\Delivery\VonageOtpDeliveryProvider;
use App\Services\Otp\Delivery\VonageSmsDeliveryReceiptReconciler;
use Illuminate\Support\Str;
use Tests\Support\Otp\FakeOtpCodeGenerator;
use Tests\Support\Otp\FakeVonageSmsSendGateway;

use function Tests\Support\Otp\vonageOtpDeliveryRequest;
use function Tests\Support\Otp\vonageSmsCollection;

require_once __DIR__.'/../../Support/Otp/FakeVonageSmsSendGateway.php';

function enableRegisterOtpWithVonageGateway(FakeVonageSmsSendGateway $gateway): void
{
    enableRegisterOtpWithFakeDelivery();
    config()->set('otp.p0a.delivery.driver', 'vonage');
    config()->set('vonage.api_key', 'test-key');
    config()->set('vonage.api_secret', 'test-secret');
    config()->set('vonage.sms_from', 'FAMEDIC');
    config()->set('vonage.sms_dlr.enabled', false);
    app()->instance(VonageSmsSendGateway::class, $gateway);
    app()->forgetInstance(OtpDeliveryProvider::class);
    app()->forgetInstance(AkubicaSecureOtpDeliveryOrchestrator::class);
}

function enableVonageSmsDiagnosticForIntegration(string $allowlist = '+525512345678'): void
{
    config()->set('vonage.api_key', 'test-key');
    config()->set('vonage.api_secret', 'test-secret');
    config()->set('vonage.sms_from', 'FAMEDIC');
    config()->set('vonage.sms_diagnostic.enabled', true);
    config()->set('vonage.sms_diagnostic.allowed_destinations', $allowlist);
    config()->set('vonage.sms_diagnostic.production_enabled', false);
    config()->set('vonage.sms_diagnostic.message', 'Mensaje de prueba FAMEDIC. La conexión SMS con Vonage funciona correctamente.');
}

test('administrative sms diagnostic accepts vonage status zero', function () {
    enableVonageSmsDiagnosticForIntegration('+525512345678');

    $gateway = new FakeVonageSmsSendGateway;
    $gateway->pushCollection(vonageSmsCollection(0, messageId: 'diag-message-001'));
    app()->instance(VonageSmsSendGateway::class, $gateway);

    $result = app(\App\Services\Otp\Monitoring\VonageSmsConnectionTestService::class)
        ->send(123, '+525512345678', 'send_only');

    expect($result->accepted)->toBeTrue()
        ->and($result->providerMessageIdPrefix)->toBe('diag-mes')
        ->and($gateway->calls)->toBe(1);
});

test('otp provider accepts vonage status zero and exposes provider message id', function () {
    config()->set('vonage.api_key', 'test-key');
    config()->set('vonage.api_secret', 'test-secret');
    config()->set('vonage.sms_from', 'FAMEDIC');
    config()->set('vonage.sms_dlr.enabled', false);

    $gateway = new FakeVonageSmsSendGateway;
    $gateway->pushCollection(vonageSmsCollection(0, messageId: 'otp-message-001'));
    app()->instance(VonageSmsSendGateway::class, $gateway);

    $result = app(VonageOtpDeliveryProvider::class)->send(vonageOtpDeliveryRequest());

    expect($result->resultClass)->toBe(OtpDeliveryResultClass::Accepted)
        ->and($result->providerMessageId)->toBe('otp-message-001')
        ->and($gateway->calls)->toBe(1);
});

test('otp register returns 202 and persists accepted operation when vonage returns status zero', function () {
    $gateway = new FakeVonageSmsSendGateway;
    $gateway->pushCollection(vonageSmsCollection(0, messageId: 'otp-register-001'));
    enableRegisterOtpWithVonageGateway($gateway);
    app()->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('123456'));

    $this->postJson('/api/v1/auth/register', [
        'email' => 'vonage-register@example.com',
        'phone' => '+525512345678',
        'full_name' => 'Vonage Register',
    ], [
        'X-Correlation-Id' => 'corr-vonage-register-001',
    ])->assertStatus(202);

    $operation = OtpDeliveryOperation::query()
        ->where('correlation_id', 'corr-vonage-register-001')
        ->first();

    expect($operation)->not->toBeNull()
        ->and($operation->status)->toBe('sms_accepted')
        ->and($operation->provider_message_id)->toBe('otp-register-001')
        ->and($gateway->calls)->toBe(1);
});

test('post acceptance persistence failure is fail soft and does not resend', function () {
    enableRegisterOtpWithFakeDelivery();

    $provider = Mockery::mock(OtpDeliveryProvider::class);
    $provider->shouldReceive('alias')->andReturn('vonage');
    $provider->shouldReceive('send')->once()->andReturn(new OtpDeliveryResult(
        OtpDeliveryResultClass::Accepted,
        '2xx',
        1,
        5,
        'vonage',
        providerMessageId: str_repeat('m', 300),
    ));
    app()->instance(OtpDeliveryProvider::class, $provider);
    app()->forgetInstance(AkubicaSecureOtpDeliveryOrchestrator::class);

    $challenge = \App\Models\OtpChallenge::factory()->create([
        'purpose' => 'akubica_register',
        'destination_normalized' => '+525512345678',
    ]);

    $identity = new \App\Services\Otp\Registration\RegistrationIdentity(
        email: app(\App\Services\Otp\Registration\EmailNormalizer::class)->normalize('persist-soft@example.com'),
        phone: app(\App\Services\Otp\Registration\MexicoPhoneNormalizer::class)->normalize('5512345678', 'MX'),
        fullName: 'Persist Soft',
    );

    $outcome = app(AkubicaSecureOtpDeliveryOrchestrator::class)
        ->deliverRegisterSafely($challenge, '123456', $identity, (string) Str::uuid());

    expect($outcome)->toBe(OtpDeliveryOutcome::Succeeded);
});

test('dlr disabled skips reconciliation after accepted sms', function () {
    enableRegisterOtpWithFakeDelivery();
    config()->set('vonage.sms_dlr.enabled', false);

    $provider = Mockery::mock(OtpDeliveryProvider::class);
    $provider->shouldReceive('alias')->andReturn('vonage');
    $provider->shouldReceive('send')->once()->andReturn(new OtpDeliveryResult(
        OtpDeliveryResultClass::Accepted,
        '2xx',
        1,
        5,
        'vonage',
        providerMessageId: 'no-dlr-message-001',
    ));
    app()->instance(OtpDeliveryProvider::class, $provider);

    $reconciler = Mockery::mock(VonageSmsDeliveryReceiptReconciler::class);
    $reconciler->shouldNotReceive('reconcilePendingForMessageId');
    app()->instance(VonageSmsDeliveryReceiptReconciler::class, $reconciler);
    app()->forgetInstance(AkubicaSecureOtpDeliveryOrchestrator::class);

    $challenge = \App\Models\OtpChallenge::factory()->create([
        'purpose' => 'akubica_register',
        'destination_normalized' => '+525512345678',
    ]);

    $identity = new \App\Services\Otp\Registration\RegistrationIdentity(
        email: app(\App\Services\Otp\Registration\EmailNormalizer::class)->normalize('no-dlr@example.com'),
        phone: app(\App\Services\Otp\Registration\MexicoPhoneNormalizer::class)->normalize('5512345678', 'MX'),
        fullName: 'No Dlr',
    );

    $outcome = app(AkubicaSecureOtpDeliveryOrchestrator::class)
        ->deliverRegisterSafely($challenge, '123456', $identity, (string) Str::uuid());

    expect($outcome)->toBe(OtpDeliveryOutcome::Succeeded);
});
