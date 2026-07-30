<?php

use App\Contracts\Otp\OtpCodeGenerator;
use App\Models\AkubicaRegistrationIntent;
use App\Models\OtpChallenge;
use App\Models\OtpDeliveryOperation;
use App\Models\User;
use App\Notifications\Api\V1\Auth\AkubicaSecureRegisterOtpMailNotification;
use App\Services\Otp\Delivery\AkubicaSecureOtpDeliveryOrchestrator;
use App\Services\Otp\Delivery\ArrayOtpDeliveryReservationStore;
use App\Services\Otp\Delivery\FakeOtpDeliveryProvider;
use App\Services\Otp\Delivery\OtpDeliveryReservationStore;
use App\Services\Otp\Delivery\OtpDeliveryResultClass;
use App\Services\Otp\Registration\AkubicaRegisterOtpDecoyStore;
use App\Services\Otp\Registration\AkubicaRegistrationIntentService;
use App\Services\Otp\Registration\AkubicaRegistrationPolicy;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\Otp\FakeOtpCodeGenerator;

function p0a57bEnableSecureRegister(): void
{
    enableRegisterOtpWithoutDelivery();
}

function p0a57bEnableDelivery(): void
{
    enableRegisterOtpWithFakeDelivery();
}

/**
 * @return array{response: \Illuminate\Testing\TestResponse, challenge_id: string|null, email: string, phone: string, code: string}
 */
function p0a57bRequestRegister(string $email, string $phone, string $code = '123456'): array
{
    app()->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator($code));

    $response = test()->postJson('/api/v1/auth/register', [
        'email' => $email,
        'phone' => $phone,
        'full_name' => 'Nombre Apellido',
    ]);

    return [
        'response' => $response,
        'challenge_id' => $response->json('data.challenge_id'),
        'email' => $email,
        'phone' => $phone,
        'code' => $code,
    ];
}

function p0a57bSwapTestLog(): TestHandler
{
    $handler = new TestHandler;
    $logger = new Logger('testing');
    $logger->pushHandler($handler);
    Log::swap($logger);

    return $handler;
}

function p0a57bAssertLogsExcludeSecrets(TestHandler $handler, string $code, string $email, string $phoneDigitsFragment): void
{
    foreach ($handler->getRecords() as $record) {
        $blob = json_encode([
            'message' => $record['message'] ?? '',
            'context' => $record['context'] ?? [],
        ], JSON_THROW_ON_ERROR);

        expect($blob)->not->toContain($code)
            ->and($blob)->not->toContain($email)
            ->and($blob)->not->toContain($phoneDigitsFragment);
    }
}

beforeEach(function () {
    Notification::fake();
    disableAllAkubicaOtpFeatures();
    app(FakeOtpDeliveryProvider::class)->alwaysAccept();
});

test('p0a57b flags off secure register sends no delivery', function () {
    p0a57bEnableSecureRegister();

    $payload = p0a57bRequestRegister('nodelivery.p0a57b@ejemplo.test', '+52 55 1234 5701', '111111');
    $payload['response']->assertStatus(202);

    expect(app(FakeOtpDeliveryProvider::class)->sent)->toHaveCount(0)
        ->and(OtpDeliveryOperation::query()->count())->toBe(0);

    Notification::assertNothingSent();
});

test('p0a57b delivery on fake sends one sms operation', function () {
    p0a57bEnableSecureRegister();
    p0a57bEnableDelivery();

    $payload = p0a57bRequestRegister('sms.one.p0a57b@ejemplo.test', '+52 55 1234 5702', '222222');
    $payload['response']->assertStatus(202)
        ->assertJsonPath('data.channel', 'sms');

    expect(app(FakeOtpDeliveryProvider::class)->sent)->toHaveCount(1)
        ->and(OtpDeliveryOperation::query()->count())->toBe(1)
        ->and(OtpDeliveryOperation::query()->first()->status)->toBe('sms_accepted');
});

test('p0a57b decoy register sends no sms', function () {
    p0a57bEnableSecureRegister();
    p0a57bEnableDelivery();

    User::factory()->create([
        'email' => 'decoy.p0a57b@ejemplo.test',
        'phone' => '5512345703',
        'phone_country' => 'MX',
    ]);

    $payload = p0a57bRequestRegister('decoy.p0a57b@ejemplo.test', '+52 55 1234 5704', '333333');
    $payload['response']->assertStatus(202);

    expect(app(FakeOtpDeliveryProvider::class)->sent)->toHaveCount(0)
        ->and(OtpDeliveryOperation::query()->count())->toBe(0)
        ->and(OtpChallenge::query()->count())->toBe(0)
        ->and(app(AkubicaRegisterOtpDecoyStore::class)->exists($payload['challenge_id']))->toBeTrue();
});

test('p0a57b duplicate orchestrator call does not duplicate sms', function () {
    p0a57bEnableSecureRegister();
    p0a57bEnableDelivery();

    $payload = p0a57bRequestRegister('dup.orc.p0a57b@ejemplo.test', '+52 55 1234 5705', '444444');
    $payload['response']->assertStatus(202);

    expect(app(FakeOtpDeliveryProvider::class)->sent)->toHaveCount(1);

    $challenge = OtpChallenge::query()->where('public_id', $payload['challenge_id'])->firstOrFail();
    $intent = AkubicaRegistrationIntent::query()->where('otp_challenge_id', $challenge->id)->firstOrFail();
    $identity = app(AkubicaRegistrationIntentService::class)->readPayload((int) $intent->id)->toRegistrationIdentity();

    app(AkubicaSecureOtpDeliveryOrchestrator::class)->deliverRegisterSafely(
        $challenge,
        $payload['code'],
        $identity,
        (string) Str::uuid(),
    );

    expect(app(FakeOtpDeliveryProvider::class)->sent)->toHaveCount(1)
        ->and(OtpDeliveryOperation::query()->count())->toBe(1);
});

test('p0a57b sms timeout with email fallback sends mail notification', function () {
    p0a57bEnableSecureRegister();
    p0a57bEnableDelivery();
    config()->set('otp.p0a.flags.email_fallback_enabled', true);
    app(FakeOtpDeliveryProvider::class)->failOnceWith(OtpDeliveryResultClass::Timeout);

    $payload = p0a57bRequestRegister('fallback.p0a57b@ejemplo.test', '+52 55 1234 5706', '555555');
    $payload['response']->assertStatus(202);

    expect(app(FakeOtpDeliveryProvider::class)->sent)->toHaveCount(1)
        ->and(OtpDeliveryOperation::query()->first()->fallback_used)->toBeTrue()
        ->and(OtpDeliveryOperation::query()->first()->status)->toBe('email_accepted');

    Notification::assertSentOnDemand(AkubicaSecureRegisterOtpMailNotification::class);
});

test('p0a57b permanent sms failure without fallback returns delivery failed', function () {
    p0a57bEnableSecureRegister();
    p0a57bEnableDelivery();
    config()->set('otp.p0a.flags.email_fallback_enabled', false);
    app(FakeOtpDeliveryProvider::class)->failAlwaysWith(OtpDeliveryResultClass::ProviderPermanentFailure);

    $payload = p0a57bRequestRegister('perm.fail.p0a57b@ejemplo.test', '+52 55 1234 5707', '666666');
    $payload['response']->assertStatus(503)
        ->assertJsonPath('error.code', 'DELIVERY_FAILED')
        ->assertJsonMissingPath('data.challenge_id');

    $operation = OtpDeliveryOperation::query()->latest('id')->first();
    expect($operation)->not->toBeNull()
        ->and($operation->status)->toBe('sms_permanent_failed');
    Notification::assertNothingSent();

    $challenge = OtpChallenge::query()->find($operation->otp_challenge_id);
    expect($challenge)->not->toBeNull()
        ->and($challenge->invalidated_at)->not->toBeNull()
        ->and($challenge->invalidated_reason)->toBe('delivery_failed');

    $intent = AkubicaRegistrationIntent::query()->where('otp_challenge_id', $challenge->id)->first();
    expect($intent)->not->toBeNull()
        ->and($intent->status->value)->toBe('INVALIDATED')
        ->and($intent->invalidation_reason->value)->toBe('delivery_failed')
        ->and($intent->encrypted_payload)->toBeNull();
});

test('p0a57b reservation store unavailable returns otp temporary unavailable', function () {
    p0a57bEnableSecureRegister();
    p0a57bEnableDelivery();

    $store = new ArrayOtpDeliveryReservationStore;
    $store->unavailable = true;
    app()->instance(OtpDeliveryReservationStore::class, $store);

    $payload = p0a57bRequestRegister('redis.down.p0a57b@ejemplo.test', '+52 55 1234 5708', '777777');
    $payload['response']->assertStatus(503)
        ->assertJsonPath('error.code', 'OTP_TEMPORARY_UNAVAILABLE');
});

test('p0a57b delivery logs exclude otp phone and email', function () {
    p0a57bEnableSecureRegister();
    p0a57bEnableDelivery();

    $email = 'log.safe.p0a57b@ejemplo.test';
    $code = '888888';
    $handler = p0a57bSwapTestLog();
    p0a57bRequestRegister($email, '+52 55 1234 5709', $code)['response']->assertStatus(202);
    p0a57bAssertLogsExcludeSecrets($handler, $code, $email, '5709');
});

test('p0a57b secure register response includes sms channel', function () {
    p0a57bEnableSecureRegister();
    p0a57bEnableDelivery();

    p0a57bRequestRegister('channel.p0a57b@ejemplo.test', '+52 55 1234 5710', '999999')['response']
        ->assertStatus(202)
        ->assertJsonPath('data.channel', 'sms')
        ->assertJsonPath('data.purpose', 'akubica_register');
});

test('p0a57b patient ready false and sanctum expiration 1440', function () {
    p0a57bEnableSecureRegister();
    p0a57bEnableDelivery();

    expect(AkubicaRegistrationPolicy::isPatientReady())->toBeFalse()
        ->and(config('sanctum.expiration'))->toBe(1440);

    p0a57bRequestRegister('sanctum.p0a57b@ejemplo.test', '+52 55 1234 5711', '101010')['response']
        ->assertStatus(202);

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('p0a57b delivery off asserts no notifications on secure register', function () {
    p0a57bEnableSecureRegister();

    p0a57bRequestRegister('nonotify.p0a57b@ejemplo.test', '+52 55 1234 5712', '121212')['response']
        ->assertStatus(202);

    Notification::assertNothingSent();
});

