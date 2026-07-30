<?php

use App\Contracts\Otp\OtpCodeGenerator;
use App\Enums\AkubicaRegistrationIntentStatus;
use App\Models\AkubicaRegistrationIntent;
use App\Models\OtpChallenge;
use App\Models\OtpCode;
use App\Models\OtpDeliveryOperation;
use App\Models\User;
use App\Notifications\Api\V1\Auth\AkubicaOtpNotification;
use App\Services\Otp\Delivery\AkubicaSecureOtpDeliveryOrchestrator;
use App\Services\Otp\Delivery\FakeOtpDeliveryProvider;
use App\Services\Otp\Delivery\OtpDeliveryOutcome;
use App\Services\Otp\Delivery\OtpDeliveryResultClass;
use App\Services\Otp\Registration\NormalizedEmail;
use App\Services\Otp\Registration\PhoneIdentity;
use App\Services\Otp\Registration\RegistrationIdentity;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\Otp\FakeOtpCodeGenerator;

function p0a57cEnableSecureRegister(): void
{
    enableRegisterOtpWithoutDelivery();
}

function p0a57cEnableDelivery(): void
{
    enableRegisterOtpWithFakeDelivery();
}

beforeEach(function () {
    Notification::fake();
    disableAllAkubicaOtpFeatures();
    app(FakeOtpDeliveryProvider::class)->alwaysAccept();
});

test('p0a57c infrastructure off ignores register flag and uses legacy', function () {
    config()->set('otp.p0a.flags.akubica_register_enabled', true);
    config()->set('otp.p0a.flags.infrastructure_enabled', false);
    config()->set('otp.p0a.flags.anti_abuse_enabled', true);

    $this->postJson('/api/v1/auth/register', [
        'email' => 'infra.off.p0a57c@ejemplo.test',
        'phone' => '+52 55 1234 5801',
        'full_name' => 'Nombre Apellido',
    ])
        ->assertOk()
        ->assertJsonPath('data.verification_sent', true)
        ->assertJsonPath('data.channel', 'email');

    expect(OtpChallenge::query()->count())->toBe(0)
        ->and(AkubicaRegistrationIntent::query()->count())->toBe(0)
        ->and(OtpCode::query()->where('email', 'infra.off.p0a57c@ejemplo.test')->count())->toBe(1);

    Notification::assertSentOnDemand(AkubicaOtpNotification::class);
});

test('p0a57c register creates intent challenge with hashed otp', function () {
    p0a57cEnableSecureRegister();
    app()->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('654321'));

    $this->postJson('/api/v1/auth/register', [
        'email' => 'hash.p0a57c@ejemplo.test',
        'phone' => '+52 55 1234 5802',
        'full_name' => 'Nombre Apellido',
    ])->assertStatus(202);

    $challenge = OtpChallenge::query()->latest('id')->first();
    expect($challenge)->not->toBeNull()
        ->and($challenge->code_hash)->not->toBe('654321')
        ->and(Hash::check('654321', $challenge->code_hash))->toBeTrue()
        ->and(AkubicaRegistrationIntent::query()->where('otp_challenge_id', $challenge->id)->exists())->toBeTrue();
});

test('p0a57c temporary sms failure without fallback returns delivery failed', function () {
    p0a57cEnableSecureRegister();
    p0a57cEnableDelivery();
    config()->set('otp.p0a.flags.email_fallback_enabled', false);
    app(FakeOtpDeliveryProvider::class)->failAlwaysWith(OtpDeliveryResultClass::Timeout);

    $response = $this->postJson('/api/v1/auth/register', [
        'email' => 'tmp.fail.p0a57c@ejemplo.test',
        'phone' => '+52 55 1234 5803',
        'full_name' => 'Nombre Apellido',
    ]);

    $response->assertStatus(503)
        ->assertJsonPath('error.code', 'DELIVERY_FAILED');

    $body = json_encode($response->json());
    expect($body)->not->toContain('VONAGE')
        ->and($body)->not->toContain('api_key')
        ->and($body)->not->toContain('Timeout')
        ->and(OtpDeliveryOperation::query()->where('status', 'sms_temporary_failed')->count())->toBe(1);

    Notification::assertNothingSent();
});

test('p0a57c sms success does not send email even with fallback enabled', function () {
    p0a57cEnableSecureRegister();
    p0a57cEnableDelivery();
    config()->set('otp.p0a.flags.email_fallback_enabled', true);

    $this->postJson('/api/v1/auth/register', [
        'email' => 'sms.only.p0a57c@ejemplo.test',
        'phone' => '+52 55 1234 5810',
        'full_name' => 'Nombre Apellido',
    ])->assertStatus(202);

    expect(app(FakeOtpDeliveryProvider::class)->sent)->toHaveCount(1)
        ->and(OtpDeliveryOperation::query()->value('status'))->toBe('sms_accepted');
    Notification::assertNothingSent();
});

test('p0a57c invalid email blocks fallback and returns failed outcome', function () {
    p0a57cEnableSecureRegister();
    p0a57cEnableDelivery();
    config()->set('otp.p0a.flags.email_fallback_enabled', true);

    $challenge = OtpChallenge::factory()->create([
        'purpose' => 'akubica_register',
        'channel' => 'sms',
        'expires_at' => now()->addMinutes(10),
    ]);

    $identityNoPhone = new RegistrationIdentity(
        new NormalizedEmail('not-an-email'),
        new PhoneIdentity('MX', '', null, 'MX|'),
        'Nombre Apellido',
    );

    $outcome = app(AkubicaSecureOtpDeliveryOrchestrator::class)->deliverRegisterSafely(
        $challenge,
        '123456',
        $identityNoPhone,
        (string) Str::uuid(),
    );

    expect($outcome)->toBe(OtpDeliveryOutcome::Failed);
    Notification::assertNothingSent();
    expect(OtpDeliveryOperation::query()->where('otp_challenge_id', $challenge->id)->value('result_class'))
        ->toBe(OtpDeliveryResultClass::FallbackFailed->value);
});

test('p0a57c verify of one challenge does not consume another', function () {
    p0a57cEnableSecureRegister();
    app()->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator(['111111', '222222']));

    $first = $this->postJson('/api/v1/auth/register', [
        'email' => 'cross.a.p0a57c@ejemplo.test',
        'phone' => '+52 55 1234 5806',
        'full_name' => 'Nombre Apellido',
    ])->assertStatus(202);

    $second = $this->postJson('/api/v1/auth/register', [
        'email' => 'cross.b.p0a57c@ejemplo.test',
        'phone' => '+52 55 1234 5807',
        'full_name' => 'Nombre Apellido',
    ])->assertStatus(202);

    $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $first->json('data.challenge_id'),
        'code' => '222222',
    ])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_CODE');

    expect(User::query()->where('email', 'cross.a.p0a57c@ejemplo.test')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'cross.b.p0a57c@ejemplo.test')->exists())->toBeFalse();

    $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $second->json('data.challenge_id'),
        'code' => '222222',
    ])->assertOk();

    expect(User::query()->where('email', 'cross.b.p0a57c@ejemplo.test')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'cross.a.p0a57c@ejemplo.test')->exists())->toBeFalse();
});

test('p0a57c wrong code increments failed attempts', function () {
    p0a57cEnableSecureRegister();
    app()->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('333333'));

    $start = $this->postJson('/api/v1/auth/register', [
        'email' => 'attempts.p0a57c@ejemplo.test',
        'phone' => '+52 55 1234 5808',
        'full_name' => 'Nombre Apellido',
    ])->assertStatus(202);

    $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $start->json('data.challenge_id'),
        'code' => '000000',
    ])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_CODE');

    $challenge = OtpChallenge::query()->where('public_id', $start->json('data.challenge_id'))->firstOrFail();
    expect($challenge->failed_attempts)->toBeGreaterThanOrEqual(1)
        ->and($challenge->consumed_at)->toBeNull();
});

test('p0a57c consumed challenge cannot be reused', function () {
    p0a57cEnableSecureRegister();
    app()->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('444444'));

    $start = $this->postJson('/api/v1/auth/register', [
        'email' => 'reuse.p0a57c@ejemplo.test',
        'phone' => '+52 55 1234 5809',
        'full_name' => 'Nombre Apellido',
    ])->assertStatus(202);

    $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $start->json('data.challenge_id'),
        'code' => '444444',
    ])->assertOk();

    $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $start->json('data.challenge_id'),
        'code' => '444444',
    ])->assertStatus(422);

    expect(User::query()->where('email', 'reuse.p0a57c@ejemplo.test')->count())->toBe(1)
        ->and(AkubicaRegistrationIntent::query()
            ->where('status', AkubicaRegistrationIntentStatus::Consumed)
            ->count())->toBeGreaterThanOrEqual(1);
});

test('p0a57c incomplete flags without anti abuse fail safely', function () {
    config()->set('otp.p0a.flags.akubica_register_enabled', true);
    config()->set('otp.p0a.flags.infrastructure_enabled', true);
    config()->set('otp.p0a.flags.anti_abuse_enabled', false);

    $this->postJson('/api/v1/auth/register', [
        'email' => 'bad.flags.p0a57c@ejemplo.test',
        'phone' => '+52 55 1234 5811',
        'full_name' => 'Nombre Apellido',
    ])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'OTP_CONFIGURATION_INVALID');

    expect(OtpChallenge::query()->count())->toBe(0);
});
