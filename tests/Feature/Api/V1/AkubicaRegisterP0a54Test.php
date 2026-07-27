<?php

use App\Models\AkubicaRegistrationIntent;
use App\Models\OtpChallenge;
use App\Models\OtpCode;
use App\Models\User;
use App\Notifications\Api\V1\Auth\AkubicaOtpNotification;
use App\Services\Otp\Registration\AkubicaRegisterOtpDecoyStore;
use App\Services\Otp\Registration\AkubicaRegistrationPolicy;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;

function p0a54EnableSecureRegister(): void
{
    config()->set('otp.p0a.flags.akubica_register_enabled', true);
    config()->set('otp.p0a.flags.infrastructure_enabled', true);
    config()->set('otp.p0a.flags.anti_abuse_enabled', true);
}

beforeEach(function () {
    Notification::fake();
    config()->set('otp.p0a.flags.akubica_register_enabled', false);
    config()->set('otp.p0a.flags.infrastructure_enabled', false);
    config()->set('otp.p0a.flags.anti_abuse_enabled', false);
});

test('p0a54 flags off keep legacy register 409 and otp notification', function () {
    User::factory()->create(['email' => 'ya.p0a54@ejemplo.test']);

    $this->postJson('/api/v1/auth/register', [
        'email' => 'ya.p0a54@ejemplo.test',
        'phone' => '+52 55 1234 5801',
        'full_name' => 'Nombre Apellido',
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'EMAIL_ALREADY_REGISTERED');

    $this->postJson('/api/v1/auth/register', [
        'email' => 'nuevo.p0a54@ejemplo.test',
        'phone' => '+52 55 1234 5802',
        'full_name' => 'Nombre Apellido',
    ])
        ->assertOk()
        ->assertJsonPath('data.verification_sent', true);

    Notification::assertSentOnDemand(AkubicaOtpNotification::class);
    expect(OtpCode::query()->where('email', 'nuevo.p0a54@ejemplo.test')->count())->toBe(1)
        ->and(OtpChallenge::query()->count())->toBe(0)
        ->and(AkubicaRegistrationIntent::query()->count())->toBe(0);
});

test('p0a54 flags off keep resend feature disabled', function () {
    $this->postJson('/api/v1/auth/register/resend-code', [
        'challenge_id' => '00000000-0000-4000-8000-000000000054',
    ])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'FEATURE_DISABLED');
});

test('p0a54 register without anti abuse returns configuration error', function () {
    config()->set('otp.p0a.flags.akubica_register_enabled', true);
    config()->set('otp.p0a.flags.infrastructure_enabled', true);
    config()->set('otp.p0a.flags.anti_abuse_enabled', false);

    $this->postJson('/api/v1/auth/register', [
        'email' => 'cfg.p0a54@ejemplo.test',
        'phone' => '+52 55 1234 5803',
        'full_name' => 'Nombre Apellido',
    ])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'OTP_CONFIGURATION_INVALID');
});

test('p0a54 available identity returns 202 challenge without user or delivery', function () {
    p0a54EnableSecureRegister();

    $response = $this->postJson('/api/v1/auth/register', [
        'email' => 'disp.p0a54@ejemplo.test',
        'phone' => '+52 55 1234 5804',
        'full_name' => 'Nombre Apellido',
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('data.requires_otp', true)
        ->assertJsonPath('data.purpose', 'akubica_register')
        ->assertJsonPath('data.channel', 'email')
        ->assertJsonStructure([
            'data' => [
                'requires_otp',
                'challenge_id',
                'purpose',
                'channel',
                'destination_masked',
                'expires_at',
                'resend_available_at',
            ],
        ]);

    expect($response->json('data.expires_at'))->toMatch('/Z$/')
        ->and($response->json('data.resend_available_at'))->toMatch('/Z$/')
        ->and(User::query()->where('email', 'disp.p0a54@ejemplo.test')->exists())->toBeFalse()
        ->and(PersonalAccessToken::query()->count())->toBe(0)
        ->and(OtpChallenge::query()->count())->toBe(1)
        ->and(AkubicaRegistrationIntent::query()->count())->toBe(1)
        ->and(AkubicaRegistrationPolicy::isPatientReady())->toBeFalse();

    Notification::assertNothingSent();

    $row = AkubicaRegistrationIntent::query()->first();
    expect($row->encrypted_payload)->not->toBeNull()
        ->and($row->encrypted_payload)->not->toContain('disp.p0a54@ejemplo.test');
});

test('p0a54 email collision returns decoy 202 without challenge or intent', function () {
    p0a54EnableSecureRegister();
    User::factory()->create([
        'email' => 'ocupado.p0a54@ejemplo.test',
        'phone' => '5512345805',
        'phone_country' => 'MX',
    ]);

    $response = $this->postJson('/api/v1/auth/register', [
        'email' => 'ocupado.p0a54@ejemplo.test',
        'phone' => '+52 55 1234 5806',
        'full_name' => 'Nombre Apellido',
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('data.requires_otp', true)
        ->assertJsonPath('data.purpose', 'akubica_register');

    $challengeId = $response->json('data.challenge_id');
    expect(OtpChallenge::query()->count())->toBe(0)
        ->and(AkubicaRegistrationIntent::query()->count())->toBe(0)
        ->and(app(AkubicaRegisterOtpDecoyStore::class)->exists($challengeId))->toBeTrue()
        ->and(PersonalAccessToken::query()->count())->toBe(0);

    Notification::assertNothingSent();
});

test('p0a54 phone collision returns decoy without revealing phone already registered', function () {
    p0a54EnableSecureRegister();
    User::factory()->create([
        'email' => 'otro.p0a54@ejemplo.test',
        'phone' => '5512345807',
        'phone_country' => 'MX',
    ]);

    $response = $this->postJson('/api/v1/auth/register', [
        'email' => 'fresh.phone.p0a54@ejemplo.test',
        'phone' => '+52 55 1234 5807',
        'full_name' => 'Nombre Apellido',
    ]);

    $response->assertStatus(202)
        ->assertJsonMissingPath('error.code');

    expect($response->json())->not->toHaveKey('error')
        ->and(OtpChallenge::query()->count())->toBe(0)
        ->and(AkubicaRegistrationIntent::query()->count())->toBe(0);
});

test('p0a54 decoy and real request share public contract keys', function () {
    p0a54EnableSecureRegister();
    User::factory()->create([
        'email' => 'decoy.cmp@ejemplo.test',
        'phone' => '5512345808',
        'phone_country' => 'MX',
    ]);

    $real = $this->postJson('/api/v1/auth/register', [
        'email' => 'real.cmp@ejemplo.test',
        'phone' => '+52 55 1234 5809',
        'full_name' => 'Nombre Apellido',
    ])->assertStatus(202)->json('data');

    $decoy = $this->postJson('/api/v1/auth/register', [
        'email' => 'decoy.cmp@ejemplo.test',
        'phone' => '+52 55 1234 5810',
        'full_name' => 'Nombre Apellido',
    ])->assertStatus(202)->json('data');

    expect(array_keys($real))->toEqual(array_keys($decoy))
        ->and($real['purpose'])->toBe($decoy['purpose'])
        ->and($real['channel'])->toBe($decoy['channel']);
});

test('p0a54 resend after real request returns new challenge and supersedes intent', function () {
    p0a54EnableSecureRegister();

    $first = $this->postJson('/api/v1/auth/register', [
        'email' => 'resend.real@ejemplo.test',
        'phone' => '+52 55 1234 5811',
        'full_name' => 'Nombre Apellido',
    ])->assertStatus(202);

    $firstId = $first->json('data.challenge_id');
    $oldIntent = AkubicaRegistrationIntent::query()->first();

    $this->travel(61)->seconds();

    $second = $this->postJson('/api/v1/auth/register/resend-code', [
        'challenge_id' => $firstId,
    ])->assertStatus(202);

    $newId = $second->json('data.challenge_id');
    expect($newId)->not->toBe($firstId)
        ->and(AkubicaRegistrationIntent::query()->find($oldIntent->id)->status->value)->toBe('SUPERSEDED')
        ->and(AkubicaRegistrationIntent::query()->find($oldIntent->id)->encrypted_payload)->toBeNull()
        ->and(AkubicaRegistrationIntent::query()->where('status', 'PENDING')->count())->toBe(1);

    Notification::assertNothingSent();
});

test('p0a54 decoy resend cooldown then issues new decoy id', function () {
    p0a54EnableSecureRegister();
    User::factory()->create([
        'email' => 'decoy.rs@ejemplo.test',
        'phone' => '5512345812',
        'phone_country' => 'MX',
    ]);

    $start = $this->postJson('/api/v1/auth/register', [
        'email' => 'decoy.rs@ejemplo.test',
        'phone' => '+52 55 1234 5813',
        'full_name' => 'Nombre Apellido',
    ])->assertStatus(202);

    $id = $start->json('data.challenge_id');

    $this->postJson('/api/v1/auth/register/resend-code', [
        'challenge_id' => $id,
    ])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'OTP_COOLDOWN')
        ->assertHeader('Retry-After');

    $this->travel(61)->seconds();

    $resend = $this->postJson('/api/v1/auth/register/resend-code', [
        'challenge_id' => $id,
    ])->assertStatus(202);

    expect($resend->json('data.challenge_id'))->not->toBe($id)
        ->and(OtpChallenge::query()->count())->toBe(0)
        ->and(AkubicaRegistrationIntent::query()->count())->toBe(0)
        ->and(app(AkubicaRegisterOtpDecoyStore::class)->exists($id))->toBeTrue();
});

test('p0a54 resend of never issued uuid returns no active code', function () {
    p0a54EnableSecureRegister();

    $this->postJson('/api/v1/auth/register/resend-code', [
        'challenge_id' => '00000000-0000-4000-8000-000000000099',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'NO_ACTIVE_CODE');
});

test('p0a54 verify remains disabled while secure register flag is on', function () {
    p0a54EnableSecureRegister();

    $this->postJson('/api/v1/auth/register/verify-code', [
        'email' => 'verify.block@ejemplo.test',
        'code' => '123456',
    ])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'FEATURE_DISABLED');

    expect(User::query()->where('email', 'verify.block@ejemplo.test')->exists())->toBeFalse()
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

test('p0a54 invalid register body returns 422 without creating intent', function () {
    p0a54EnableSecureRegister();

    $this->postJson('/api/v1/auth/register', [
        'email' => 'not-an-email',
        'phone' => '123',
        'full_name' => 'ab',
    ])->assertStatus(422);

    expect(OtpChallenge::query()->count())->toBe(0)
        ->and(AkubicaRegistrationIntent::query()->count())->toBe(0);
});

test('p0a54 patient ready stays false and sanctum remains 1440', function () {
    p0a54EnableSecureRegister();

    expect(AkubicaRegistrationPolicy::isPatientReady())->toBeFalse()
        ->and(AkubicaRegistrationPolicy::canOperate())->toBeTrue()
        ->and(config('sanctum.expiration'))->toBe(1440)
        ->and(config('otp.p0a.flags.akubica_login_enabled'))->toBeFalse();
});
