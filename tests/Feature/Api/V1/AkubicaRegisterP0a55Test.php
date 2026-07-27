<?php

use App\Actions\Api\V1\Auth\IssueAkubicaTokenAction;
use App\Actions\Api\V1\Auth\RegisterAkubicaCustomerAction;
use App\Contracts\Otp\OtpCodeGenerator;
use App\Enums\AkubicaRegistrationIntentStatus;
use App\Models\AkubicaRegistrationIntent;
use App\Models\Customer;
use App\Models\OtpChallenge;
use App\Models\OtpCode;
use App\Models\RegularAccount;
use App\Models\User;
use App\Notifications\Api\V1\Auth\AkubicaOtpNotification;
use App\Services\Otp\Registration\AkubicaRegisterOtpDecoyStore;
use App\Services\Otp\Registration\AkubicaRegistrationPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\Otp\FakeOtpCodeGenerator;

function p0a55EnableSecureRegister(): void
{
    config()->set('otp.p0a.flags.akubica_register_enabled', true);
    config()->set('otp.p0a.flags.infrastructure_enabled', true);
    config()->set('otp.p0a.flags.anti_abuse_enabled', true);
}

/**
 * @return array{challenge_id: string, email: string, phone: string}
 */
function p0a55RequestRegister(string $email, string $phone, string $code = '123456'): array
{
    app()->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator($code));

    $response = test()->postJson('/api/v1/auth/register', [
        'email' => $email,
        'phone' => $phone,
        'full_name' => 'Nombre Apellido',
    ])->assertStatus(202);

    return [
        'challenge_id' => $response->json('data.challenge_id'),
        'email' => $email,
        'phone' => $phone,
    ];
}

beforeEach(function () {
    Notification::fake();
    config()->set('otp.p0a.flags.akubica_register_enabled', false);
    config()->set('otp.p0a.flags.infrastructure_enabled', false);
    config()->set('otp.p0a.flags.anti_abuse_enabled', false);
});

// ── A. Feature gating ──────────────────────────────────────────────────

test('p0a55 flags off keep legacy verify email+code token flow', function () {
    $email = 'legacy.verify.p0a55@ejemplo.test';
    $this->postJson('/api/v1/auth/register', [
        'email' => $email,
        'phone' => '+52 55 1234 5901',
        'full_name' => 'Nombre Apellido',
    ])->assertOk();

    $otp = OtpCode::query()->where('email', $email)->first();
    expect($otp)->not->toBeNull();

    // Extract is impossible (hashed); use dedicated create path via notification fake + known code in legacy tests.
    // Legacy path already covered by AkubicaAuthTest; here assert flag OFF still rejects challenge_id-only body.
    $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => '00000000-0000-4000-8000-000000000055',
        'code' => '123456',
    ])->assertStatus(422);

    expect(AkubicaRegistrationPolicy::isPatientReady())->toBeFalse()
        ->and(config('otp.p0a.flags.akubica_register_enabled'))->toBeFalse();
});

test('p0a55 incomplete flags do not enable verify', function () {
    config()->set('otp.p0a.flags.akubica_register_enabled', true);
    config()->set('otp.p0a.flags.infrastructure_enabled', true);
    config()->set('otp.p0a.flags.anti_abuse_enabled', false);

    $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => '00000000-0000-4000-8000-000000000056',
        'code' => '123456',
    ])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'OTP_CONFIGURATION_INVALID');
});

test('p0a55 patient ready stays false and sanctum remains 1440', function () {
    p0a55EnableSecureRegister();

    expect(AkubicaRegistrationPolicy::isPatientReady())->toBeFalse()
        ->and(config('sanctum.expiration'))->toBe(1440)
        ->and(config('otp.p0a.flags.akubica_login_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.akubica_register_enabled'))->toBeTrue();
});

// ── B. Happy path ──────────────────────────────────────────────────────

test('p0a55 happy path creates accounts consumes intent and issues one token', function () {
    p0a55EnableSecureRegister();
    $start = p0a55RequestRegister('happy.p0a55@ejemplo.test', '+52 55 1234 5902', '654321');

    $beforeUsers = User::query()->count();
    $beforeRa = RegularAccount::query()->count();
    $beforeCustomers = Customer::query()->count();
    $beforeTokens = PersonalAccessToken::query()->count();

    $response = $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $start['challenge_id'],
        'code' => '654321',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonStructure([
            'data' => ['token', 'token_type', 'expires_in', 'expires_at', 'user' => ['id', 'email', 'name']],
        ]);

    expect($response->json('data.expires_at'))->toMatch('/Z$/')
        ->and($response->json('data.user.email'))->toBe('happy.p0a55@ejemplo.test')
        ->and(User::query()->count())->toBe($beforeUsers + 1)
        ->and(RegularAccount::query()->count())->toBe($beforeRa + 1)
        ->and(Customer::query()->count())->toBe($beforeCustomers + 1)
        ->and(PersonalAccessToken::query()->count())->toBe($beforeTokens + 1);

    $user = User::query()->where('email', 'happy.p0a55@ejemplo.test')->first();
    expect($user)->not->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->customer)->not->toBeNull()
        ->and($user->customer->customerable_type)->toBe(RegularAccount::class);

    $challenge = OtpChallenge::query()->where('public_id', $start['challenge_id'])->first();
    $intent = AkubicaRegistrationIntent::query()->where('otp_challenge_id', $challenge->id)->first();

    expect($challenge->consumed_at)->not->toBeNull()
        ->and($intent->status)->toBe(AkubicaRegistrationIntentStatus::Consumed)
        ->and($intent->encrypted_payload)->toBeNull()
        ->and(DB::table('akubica_registration_intents')->where('id', $intent->id)->value('encrypted_payload'))->toBeNull();

    Notification::assertNothingSent();
    expect($response->json())->not->toHaveKey('error');
});

// ── C. Security ────────────────────────────────────────────────────────

test('p0a55 wrong otp does not create accounts or token', function () {
    p0a55EnableSecureRegister();
    $start = p0a55RequestRegister('wrong.p0a55@ejemplo.test', '+52 55 1234 5903', '111111');

    $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $start['challenge_id'],
        'code' => '000000',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_CODE');

    expect(User::query()->where('email', 'wrong.p0a55@ejemplo.test')->exists())->toBeFalse()
        ->and(PersonalAccessToken::query()->count())->toBe(0)
        ->and(AkubicaRegistrationIntent::query()->where('status', 'PENDING')->count())->toBe(1);
});

test('p0a55 decoy verify never creates accounts or token', function () {
    p0a55EnableSecureRegister();
    User::factory()->create([
        'email' => 'decoy.owner.p0a55@ejemplo.test',
        'phone' => '5512345904',
        'phone_country' => 'MX',
    ]);

    $decoy = $this->postJson('/api/v1/auth/register', [
        'email' => 'decoy.owner.p0a55@ejemplo.test',
        'phone' => '+52 55 1234 5905',
        'full_name' => 'Nombre Apellido',
    ])->assertStatus(202);

    $id = $decoy->json('data.challenge_id');
    expect(app(AkubicaRegisterOtpDecoyStore::class)->exists($id))->toBeTrue();

    $response = $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $id,
        'code' => '123456',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_CODE');

    expect(User::query()->where('email', '!=', 'decoy.owner.p0a55@ejemplo.test')->count())->toBe(0)
        ->and(OtpChallenge::query()->count())->toBe(0)
        ->and(AkubicaRegistrationIntent::query()->count())->toBe(0)
        ->and(PersonalAccessToken::query()->count())->toBe(0)
        ->and(json_encode($response->json()))->not->toContain('decoy');
});

test('p0a55 unknown challenge returns no active code', function () {
    p0a55EnableSecureRegister();

    $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => '00000000-0000-4000-8000-000000000099',
        'code' => '123456',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'NO_ACTIVE_CODE');
});

test('p0a55 expired challenge returns code expired', function () {
    p0a55EnableSecureRegister();
    $start = p0a55RequestRegister('expired.p0a55@ejemplo.test', '+52 55 1234 5906', '222222');

    OtpChallenge::query()->where('public_id', $start['challenge_id'])->update([
        'expires_at' => now()->subMinute(),
    ]);
    AkubicaRegistrationIntent::query()->update([
        'expires_at' => now()->subMinute(),
    ]);

    $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $start['challenge_id'],
        'code' => '222222',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'CODE_EXPIRED');

    expect(User::query()->where('email', 'expired.p0a55@ejemplo.test')->exists())->toBeFalse();
});

test('p0a55 verify rejects identity substitution fields', function () {
    p0a55EnableSecureRegister();
    $start = p0a55RequestRegister('subst.p0a55@ejemplo.test', '+52 55 1234 5907', '333333');

    $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $start['challenge_id'],
        'code' => '333333',
        'email' => 'attacker@ejemplo.test',
        'phone' => '+52 55 9999 9999',
    ])->assertStatus(422);

    expect(User::query()->where('email', 'subst.p0a55@ejemplo.test')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'attacker@ejemplo.test')->exists())->toBeFalse();
});

test('p0a55 mid verify collision returns invalid code without accounts', function () {
    p0a55EnableSecureRegister();
    $start = p0a55RequestRegister('race.p0a55@ejemplo.test', '+52 55 1234 5908', '444444');

    User::factory()->create([
        'email' => 'race.p0a55@ejemplo.test',
        'phone' => '5512345999',
        'phone_country' => 'MX',
    ]);

    $response = $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $start['challenge_id'],
        'code' => '444444',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_CODE');

    expect(json_encode($response->json()))->not->toContain('EMAIL_ALREADY')
        ->and(json_encode($response->json()))->not->toContain('race.p0a55')
        ->and(PersonalAccessToken::query()->count())->toBe(0);

    $intent = AkubicaRegistrationIntent::query()->first();
    expect($intent->status)->toBe(AkubicaRegistrationIntentStatus::Invalidated)
        ->and($intent->encrypted_payload)->toBeNull();
});

test('p0a55 corrupt payload invalidates without leaking ciphertext', function () {
    p0a55EnableSecureRegister();
    $start = p0a55RequestRegister('corrupt.p0a55@ejemplo.test', '+52 55 1234 5909', '555555');

    $challenge = OtpChallenge::query()->where('public_id', $start['challenge_id'])->first();
    AkubicaRegistrationIntent::query()->where('otp_challenge_id', $challenge->id)->update([
        'encrypted_payload' => 'not-valid-ciphertext',
    ]);

    $response = $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $start['challenge_id'],
        'code' => '555555',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_CODE');

    expect(json_encode($response->json()))->not->toContain('not-valid-ciphertext')
        ->and(User::query()->where('email', 'corrupt.p0a55@ejemplo.test')->exists())->toBeFalse();
});

test('p0a55 intent serialization stays free of ciphertext and fingerprint', function () {
    p0a55EnableSecureRegister();
    p0a55RequestRegister('ser.p0a55@ejemplo.test', '+52 55 1234 5910', '666666');

    $intent = AkubicaRegistrationIntent::query()->first();
    $array = $intent->toArray();
    $json = $intent->toJson();

    expect($array)->not->toHaveKey('encrypted_payload')
        ->and($array)->not->toHaveKey('email_fingerprint')
        ->and($json)->not->toContain('ser.p0a55@ejemplo.test');
});

// ── D. Atomicity ───────────────────────────────────────────────────────

test('p0a55 create failure rolls back without consuming challenge or intent', function () {
    p0a55EnableSecureRegister();
    $start = p0a55RequestRegister('fail.create.p0a55@ejemplo.test', '+52 55 1234 5911', '777777');

    $this->mock(RegisterAkubicaCustomerAction::class, function ($mock) {
        $mock->shouldReceive('__invoke')->once()->andThrow(new RuntimeException('forced create failure'));
    });

    $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $start['challenge_id'],
        'code' => '777777',
    ])->assertStatus(500);

    expect(User::query()->where('email', 'fail.create.p0a55@ejemplo.test')->exists())->toBeFalse()
        ->and(RegularAccount::query()->count())->toBe(0)
        ->and(Customer::query()->count())->toBe(0)
        ->and(PersonalAccessToken::query()->count())->toBe(0);

    $challenge = OtpChallenge::query()->where('public_id', $start['challenge_id'])->first();
    $intent = AkubicaRegistrationIntent::query()->where('otp_challenge_id', $challenge->id)->first();

    expect($challenge->consumed_at)->toBeNull()
        ->and($intent->status)->toBe(AkubicaRegistrationIntentStatus::Pending)
        ->and($intent->encrypted_payload)->not->toBeNull();
});

test('p0a55 token issuance failure leaves consumed registration without second token path', function () {
    p0a55EnableSecureRegister();
    $start = p0a55RequestRegister('token.fail.p0a55@ejemplo.test', '+52 55 1234 5912', '888888');

    $this->mock(IssueAkubicaTokenAction::class, function ($mock) {
        $mock->shouldReceive('__invoke')->once()->andThrow(new RuntimeException('forced token failure'));
    });

    // P0-A5.7A / D11: controlled LOGIN_REQUIRED (recover via P0-A4 login), never INTERNAL_ERROR.
    $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $start['challenge_id'],
        'code' => '888888',
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'LOGIN_REQUIRED');

    expect(User::query()->where('email', 'token.fail.p0a55@ejemplo.test')->exists())->toBeTrue()
        ->and(PersonalAccessToken::query()->count())->toBe(0);

    $challenge = OtpChallenge::query()->where('public_id', $start['challenge_id'])->first();
    expect($challenge->consumed_at)->not->toBeNull();

    // Recovery is login (P0-A4), not re-verify.
    $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $start['challenge_id'],
        'code' => '888888',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'CODE_ALREADY_USED');
});

// ── E. Reintentos ──────────────────────────────────────────────────────

test('p0a55 sequential double submit creates one account and one token', function () {
    p0a55EnableSecureRegister();
    $start = p0a55RequestRegister('double.p0a55@ejemplo.test', '+52 55 1234 5913', '999999');

    $first = $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $start['challenge_id'],
        'code' => '999999',
    ])->assertOk();

    $second = $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $start['challenge_id'],
        'code' => '999999',
    ]);

    $second->assertStatus(422)
        ->assertJsonPath('error.code', 'CODE_ALREADY_USED');

    expect(User::query()->where('email', 'double.p0a55@ejemplo.test')->count())->toBe(1)
        ->and(PersonalAccessToken::query()->count())->toBe(1)
        ->and($first->json('data.token'))->not->toBeNull()
        ->and($second->json())->not->toHaveKey('data.token');
});

// ── F. Side effects / delivery ─────────────────────────────────────────

test('p0a55 verify path sends no notifications', function () {
    p0a55EnableSecureRegister();
    $start = p0a55RequestRegister('silent.p0a55@ejemplo.test', '+52 55 1234 5914', '121212');

    $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $start['challenge_id'],
        'code' => '121212',
    ])->assertOk();

    Notification::assertNothingSent();
    Notification::assertNotSentTo(
        User::query()->where('email', 'silent.p0a55@ejemplo.test')->first(),
        AkubicaOtpNotification::class,
    );
});
