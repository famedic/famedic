<?php

use App\Contracts\Otp\OtpCodeGenerator;
use App\Models\OtpAbuseEvent;
use App\Models\OtpChallenge;
use App\Models\OtpCode;
use App\Models\OtpRateLimit;
use App\Models\User;
use App\Notifications\Api\V1\Auth\AkubicaOtpNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\Otp\FakeOtpCodeGenerator;

function enableAkubicaLoginOtpFlags(): void
{
    config()->set('otp.p0a.flags.akubica_login_enabled', true);
    config()->set('otp.p0a.flags.anti_abuse_enabled', true);
}

function disableAkubicaLoginOtpFlags(): void
{
    config()->set('otp.p0a.flags.akubica_login_enabled', false);
    config()->set('otp.p0a.flags.anti_abuse_enabled', false);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-23 18:00:00'));
    Notification::fake();
    disableAkubicaLoginOtpFlags();
    config()->set('otp.p0a.policy.max_attempts', 5);
    config()->set('otp.p0a.policy.ttl_minutes', 5);
    config()->set('otp.p0a.policy.cooldown_seconds', 60);
});

afterEach(function () {
    Carbon::setTestNow();
    disableAkubicaLoginOtpFlags();
});

// ── Flags off: legacy intact ───────────────────────────────────────────

test('p0a4 flags off keeps legacy login token flow and notifications', function () {
    $user = User::factory()->create(['email' => 'legacy@ejemplo.com']);

    $this->postJson('/api/v1/auth/login/request-code', ['email' => 'legacy@ejemplo.com'])
        ->assertOk()
        ->assertJsonPath('data.verification_sent', true);

    Notification::assertSentTo($user, AkubicaOtpNotification::class);
    expect(OtpChallenge::query()->count())->toBe(0)
        ->and(OtpRateLimit::query()->count())->toBe(0);
});

test('p0a4 flags off does not create challenges for nonexistent email', function () {
    $this->postJson('/api/v1/auth/login/request-code', ['email' => 'nadie@ejemplo.com'])
        ->assertOk()
        ->assertJsonPath('data.verification_sent', true);

    Notification::assertNothingSent();
    expect(OtpChallenge::query()->count())->toBe(0)
        ->and(OtpCode::query()->count())->toBe(0);
});

test('p0a4 resend is disabled when login otp flag is off', function () {
    $this->postJson('/api/v1/auth/login/resend-code', [
        'challenge_id' => '00000000-0000-4000-8000-000000000001',
    ])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'FEATURE_DISABLED');
});

// ── Flags on: start / verify / resend ──────────────────────────────────

test('p0a4 request returns 202 challenge without token or notification', function () {
    enableAkubicaLoginOtpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('123456'));

    $user = User::factory()->create(['email' => 'otp.login@ejemplo.com']);

    $response = $this->postJson('/api/v1/auth/login/request-code', [
        'email' => 'otp.login@ejemplo.com',
    ])->assertStatus(202)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.requires_otp', true)
        ->assertJsonPath('data.purpose', 'akubica_login')
        ->assertJsonPath('data.channel', 'email');

    $json = $response->json('data');
    expect($json)->toHaveKeys(['challenge_id', 'destination_masked', 'expires_at', 'resend_available_at'])
        ->and($json)->not->toHaveKey('token')
        ->and(json_encode($json))->not->toContain('123456')
        ->and(json_encode($json))->not->toContain('otp.login@ejemplo.com');

    Notification::assertNothingSent();
    expect(OtpChallenge::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(OtpCode::query()->count())->toBe(0)
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

test('p0a4 request for nonexistent email returns decoy 202 without persistence', function () {
    enableAkubicaLoginOtpFlags();

    $response = $this->postJson('/api/v1/auth/login/request-code', [
        'email' => 'fantasma@ejemplo.com',
    ])->assertStatus(202)
        ->assertJsonPath('data.requires_otp', true)
        ->assertJsonPath('data.destination_masked', 'f***@ejemplo.com');

    $challengeId = $response->json('data.challenge_id');
    expect($challengeId)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i')
        ->and($response->json('data.destination_masked'))->not->toBeNull()
        ->and($response->json('data.destination_masked'))->not->toBe('');

    Notification::assertNothingSent();
    expect(OtpChallenge::query()->count())->toBe(0)
        ->and(OtpRateLimit::query()->count())->toBe(0)
        ->and(OtpAbuseEvent::query()->count())->toBe(0)
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

test('p0a4 decoy and existing user start responses share public contract shape', function () {
    enableAkubicaLoginOtpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('123456'));

    User::factory()->create(['email' => 'mismo.dominio@ejemplo.com']);

    $existing = $this->postJson('/api/v1/auth/login/request-code', [
        'email' => 'mismo.dominio@ejemplo.com',
    ])->assertStatus(202)->json('data');

    $decoy = $this->postJson('/api/v1/auth/login/request-code', [
        'email' => 'no.existe@ejemplo.com',
    ])->assertStatus(202)->json('data');

    $keys = ['requires_otp', 'challenge_id', 'purpose', 'channel', 'destination_masked', 'expires_at', 'resend_available_at'];
    expect(array_keys($existing))->toEqualCanonicalizing($keys)
        ->and(array_keys($decoy))->toEqualCanonicalizing($keys)
        ->and($existing['requires_otp'])->toBeTrue()
        ->and($decoy['requires_otp'])->toBeTrue()
        ->and($existing['purpose'])->toBe($decoy['purpose'])
        ->and($existing['channel'])->toBe($decoy['channel'])
        ->and(is_string($existing['challenge_id']))->toBeTrue()
        ->and(is_string($decoy['challenge_id']))->toBeTrue()
        ->and($existing['challenge_id'])->toMatch('/^[0-9a-f-]{36}$/i')
        ->and($decoy['challenge_id'])->toMatch('/^[0-9a-f-]{36}$/i')
        ->and($existing['destination_masked'])->toBe('m***@ejemplo.com')
        ->and($decoy['destination_masked'])->toBe('n***@ejemplo.com')
        ->and($existing['expires_at'])->toMatch('/Z$/')
        ->and($decoy['expires_at'])->toMatch('/Z$/')
        ->and($existing['resend_available_at'])->toMatch('/Z$/')
        ->and($decoy['resend_available_at'])->toMatch('/Z$/')
        ->and($existing)->not->toHaveKey('token')
        ->and($decoy)->not->toHaveKey('token');

    $challenge = OtpChallenge::query()->where('public_id', $existing['challenge_id'])->first();
    expect($challenge)->not->toBeNull()
        ->and($existing['challenge_id'])->not->toBe((string) $challenge->id)
        ->and(OtpChallenge::query()->where('public_id', $decoy['challenge_id'])->exists())->toBeFalse();
});

test('p0a4 verify and resend of never-issued uuid stay NO_ACTIVE_CODE', function () {
    // Distinction: only challenge_ids issued by request-code (real or decoy) are
    // the anti-enumeration surface. Random UUIDs never handed out may differ.
    enableAkubicaLoginOtpFlags();

    $unknownId = '00000000-0000-4000-8000-000000000099';

    $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $unknownId,
        'code' => '123456',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'NO_ACTIVE_CODE');

    $this->postJson('/api/v1/auth/login/resend-code', [
        'challenge_id' => $unknownId,
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'NO_ACTIVE_CODE');

    expect(PersonalAccessToken::query()->count())->toBe(0)
        ->and(OtpChallenge::query()->count())->toBe(0);
});

test('p0a4 issued decoy verify and resend match real public contracts across lifecycle', function () {
    enableAkubicaLoginOtpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator(['111111', '222222', '333333']));

    User::factory()->create(['email' => 'real.ciclo@ejemplo.com']);

    $realStart = $this->postJson('/api/v1/auth/login/request-code', [
        'email' => 'real.ciclo@ejemplo.com',
    ])->assertStatus(202);

    $decoyStart = $this->postJson('/api/v1/auth/login/request-code', [
        'email' => 'decoy.ciclo@ejemplo.com',
    ])->assertStatus(202);

    $realId = $realStart->json('data.challenge_id');
    $decoyId = $decoyStart->json('data.challenge_id');

    expect(OtpChallenge::query()->count())->toBe(1)
        ->and(OtpChallenge::query()->where('public_id', $decoyId)->exists())->toBeFalse()
        ->and(OtpRateLimit::query()->where('bucket_type', 'identity')->count())->toBe(1);

    // 1–3: wrong verify — same HTTP, code, public JSON shape
    $realWrong = $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $realId,
        'code' => '000000',
    ])->assertUnprocessable();

    $decoyWrong = $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $decoyId,
        'code' => '000000',
    ])->assertUnprocessable();

    expect($realWrong->json('error.code'))->toBe('INVALID_CODE')
        ->and($decoyWrong->json('error.code'))->toBe('INVALID_CODE')
        ->and($realWrong->json('error.message'))->toBe($decoyWrong->json('error.message'))
        ->and(array_keys($realWrong->json('error')))->toEqualCanonicalizing(array_keys($decoyWrong->json('error')));

    // 4–6: immediate resend — both 429 cooldown
    $realResendCool = $this->postJson('/api/v1/auth/login/resend-code', [
        'challenge_id' => $realId,
    ])->assertStatus(429);

    $decoyResendCool = $this->postJson('/api/v1/auth/login/resend-code', [
        'challenge_id' => $decoyId,
    ])->assertStatus(429);

    expect($realResendCool->json('error.code'))->toBe('OTP_COOLDOWN')
        ->and($decoyResendCool->json('error.code'))->toBe('OTP_COOLDOWN')
        ->and((int) $realResendCool->headers->get('Retry-After'))
        ->toBe((int) $realResendCool->json('error.details.retry_after'))
        ->and((int) $decoyResendCool->headers->get('Retry-After'))
        ->toBe((int) $decoyResendCool->json('error.details.retry_after'))
        ->and($decoyResendCool->json('error.details.retry_after'))->toBeInt()
        ->and($realResendCool->json('error.details.available_at'))->toMatch('/Z$/')
        ->and($decoyResendCool->json('error.details.available_at'))->toMatch('/Z$/');

    // 7–9: after cooldown both resend 202 with new opaque IDs
    Carbon::setTestNow(now()->addSeconds(60));

    $realResendOk = $this->postJson('/api/v1/auth/login/resend-code', [
        'challenge_id' => $realId,
    ])->assertStatus(202);

    $decoyResendOk = $this->postJson('/api/v1/auth/login/resend-code', [
        'challenge_id' => $decoyId,
    ])->assertStatus(202);

    $realId2 = $realResendOk->json('data.challenge_id');
    $decoyId2 = $decoyResendOk->json('data.challenge_id');
    $keys = ['requires_otp', 'challenge_id', 'purpose', 'channel', 'destination_masked', 'expires_at', 'resend_available_at'];

    expect($realId2)->not->toBe($realId)
        ->and($decoyId2)->not->toBe($decoyId)
        ->and($realId2)->toMatch('/^[0-9a-f-]{36}$/i')
        ->and($decoyId2)->toMatch('/^[0-9a-f-]{36}$/i')
        ->and(array_keys($realResendOk->json('data')))->toEqualCanonicalizing($keys)
        ->and(array_keys($decoyResendOk->json('data')))->toEqualCanonicalizing($keys)
        ->and($realResendOk->json('data.expires_at'))->toMatch('/Z$/')
        ->and($decoyResendOk->json('data.expires_at'))->toMatch('/Z$/')
        ->and($realResendOk->json('data.resend_available_at'))->toMatch('/Z$/')
        ->and($decoyResendOk->json('data.resend_available_at'))->toMatch('/Z$/')
        ->and(OtpChallenge::query()->where('public_id', $decoyId2)->exists())->toBeFalse();

    // 10: previous decoy reflects replacement
    $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $decoyId,
        'code' => '000000',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'CODE_INVALIDATED');

    $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $realId,
        'code' => '111111',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'CODE_INVALIDATED');

    // 11: new decoy never issues token
    $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $decoyId2,
        'code' => '123456',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVALID_CODE');

    expect(PersonalAccessToken::query()->count())->toBe(0);

    // 12: expire by TTL (same clock for real DB challenge and decoy cache)
    Carbon::setTestNow(now()->addMinutes(6));

    $realExpired = $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $realId2,
        'code' => '000000',
    ])->assertUnprocessable();

    $decoyExpired = $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $decoyId2,
        'code' => '000000',
    ])->assertUnprocessable();

    expect($realExpired->json('error.code'))->toBe('CODE_EXPIRED')
        ->and($decoyExpired->json('error.code'))->toBe('CODE_EXPIRED')
        ->and($realExpired->json('error.message'))->toBe($decoyExpired->json('error.message'));

    // 13–17: decoy side effects — no persistence / delivery / PII
    expect(OtpChallenge::query()->where('public_id', $decoyId)->exists())->toBeFalse()
        ->and(OtpChallenge::query()->where('public_id', $decoyId2)->exists())->toBeFalse()
        ->and(PersonalAccessToken::query()->count())->toBe(0);

    foreach (OtpAbuseEvent::query()->get() as $event) {
        expect(json_encode($event->toArray()))->not->toContain('decoy.ciclo@ejemplo.com')
            ->and(json_encode($event->toArray()))->not->toContain('127.0.0.1');
    }

    Notification::assertNothingSent();
});

test('p0a4 login otp without anti abuse returns configuration error', function () {
    config()->set('otp.p0a.flags.akubica_login_enabled', true);
    config()->set('otp.p0a.flags.anti_abuse_enabled', false);

    User::factory()->create(['email' => 'cfg@ejemplo.com']);

    $response = $this->postJson('/api/v1/auth/login/request-code', ['email' => 'cfg@ejemplo.com'])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'OTP_CONFIGURATION_INVALID');

    $body = json_encode($response->json());
    expect($body)->not->toContain('anti_abuse')
        ->and($body)->not->toContain('akubica_login_enabled')
        ->and($body)->not->toContain('OTP_P0A')
        ->and(OtpChallenge::query()->count())->toBe(0);
});

test('p0a4 verify issues sanctum token and consumes challenge', function () {
    enableAkubicaLoginOtpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('654321'));

    $user = User::factory()->create([
        'email' => 'verify@ejemplo.com',
        'name' => 'Paciente',
        'paternal_lastname' => 'Prueba',
    ]);

    $start = $this->postJson('/api/v1/auth/login/request-code', [
        'email' => 'verify@ejemplo.com',
    ])->assertStatus(202);

    $challengeId = $start->json('data.challenge_id');

    $verify = $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $challengeId,
        'code' => '654321',
    ])->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.email', 'verify@ejemplo.com');

    $token = $verify->json('data.token');
    $expiresIn = $verify->json('data.expires_in');
    $sanctumMinutes = (int) config('sanctum.expiration');

    expect($token)->not->toBeEmpty()
        ->and($sanctumMinutes)->toBe(1440)
        // Legacy IssueAkubicaTokenAction: seconds remaining, not minutes.
        ->and($expiresIn)->toBe($sanctumMinutes * 60)
        ->and($verify->json('data.expires_at'))->toMatch('/Z$/');

    $challenge = OtpChallenge::query()->where('public_id', $challengeId)->first();
    expect($challenge->status())->toBe(OtpChallenge::STATUS_CONSUMED);

    $this->withToken($token)
        ->deleteJson('/api/v1/auth/token')
        ->assertOk();

    $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $challengeId,
        'code' => '654321',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'CODE_ALREADY_USED');
});

test('p0a4 wrong code increments attempts and never returns plaintext otp', function () {
    enableAkubicaLoginOtpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('111111'));

    User::factory()->create(['email' => 'wrong@ejemplo.com']);
    $challengeId = $this->postJson('/api/v1/auth/login/request-code', [
        'email' => 'wrong@ejemplo.com',
    ])->json('data.challenge_id');

    $response = $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $challengeId,
        'code' => '000000',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVALID_CODE');

    expect(json_encode($response->json()))->not->toContain('111111')
        ->and(OtpChallenge::query()->where('public_id', $challengeId)->value('failed_attempts'))->toBe(1);
});

test('p0a4 max attempts invalidates and blocks further success', function () {
    enableAkubicaLoginOtpFlags();
    config()->set('otp.p0a.policy.max_attempts', 2);
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('222222'));

    User::factory()->create(['email' => 'max@ejemplo.com']);
    $challengeId = $this->postJson('/api/v1/auth/login/request-code', [
        'email' => 'max@ejemplo.com',
    ])->json('data.challenge_id');

    $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $challengeId,
        'code' => '000000',
    ])->assertJsonPath('error.code', 'INVALID_CODE');

    $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $challengeId,
        'code' => '000001',
    ])->assertJsonPath('error.code', 'OTP_MAX_ATTEMPTS');

    $blocked = $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $challengeId,
        'code' => '222222',
    ]);

    expect($blocked->status())->toBeIn([422, 429])
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

test('p0a4 cooldown returns 429 with Retry-After', function () {
    enableAkubicaLoginOtpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator(['111111', '222222']));

    User::factory()->create(['email' => 'cool@ejemplo.com']);

    $this->postJson('/api/v1/auth/login/request-code', ['email' => 'cool@ejemplo.com'])
        ->assertStatus(202);

    $response = $this->postJson('/api/v1/auth/login/request-code', ['email' => 'cool@ejemplo.com'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'OTP_COOLDOWN')
        ->assertJsonPath('error.details.retry_after', 60);

    expect($response->headers->get('Retry-After'))->toBe('60')
        ->and($response->json('error.details.retry_after'))->toBe(60)
        ->and($response->json('error.details.retry_after'))->toBeInt()
        ->and($response->json('error.details.available_at'))->toMatch('/Z$/')
        ->and(OtpChallenge::query()->count())->toBe(1);
});

test('p0a4 resend after cooldown invalidates previous challenge', function () {
    enableAkubicaLoginOtpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator(['111111', '999999']));

    User::factory()->create(['email' => 'resend@ejemplo.com']);

    $firstId = $this->postJson('/api/v1/auth/login/request-code', [
        'email' => 'resend@ejemplo.com',
    ])->json('data.challenge_id');

    Carbon::setTestNow(now()->addSeconds(60));

    $second = $this->postJson('/api/v1/auth/login/resend-code', [
        'challenge_id' => $firstId,
    ])->assertStatus(202);

    $secondId = $second->json('data.challenge_id');
    expect($secondId)->not->toBe($firstId)
        ->and(OtpChallenge::query()->where('public_id', $firstId)->first()->status())
        ->toBe(OtpChallenge::STATUS_INVALIDATED);

    $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $firstId,
        'code' => '111111',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'CODE_INVALIDATED');

    expect(PersonalAccessToken::query()->count())->toBe(0);

    $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $secondId,
        'code' => '999999',
    ])->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer');
});

test('p0a4 verify unknown challenge uses safe error', function () {
    enableAkubicaLoginOtpFlags();

    $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => '00000000-0000-4000-8000-000000000099',
        'code' => '123456',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'NO_ACTIVE_CODE');
});

test('p0a4 does not persist otp plaintext or full destination in abuse events', function () {
    enableAkubicaLoginOtpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('555555'));

    User::factory()->create(['email' => 'secreto@ejemplo.com']);
    $this->postJson('/api/v1/auth/login/request-code', ['email' => 'secreto@ejemplo.com'])
        ->assertStatus(202);

    $challenge = OtpChallenge::query()->first();
    $attrs = $challenge->getAttributes();
    expect($attrs['code_hash'])->not->toBe('555555')
        ->and(json_encode($challenge->toArray()))->not->toContain('555555');

    foreach (OtpAbuseEvent::query()->get() as $event) {
        expect(json_encode($event->toArray()))->not->toContain('secreto@ejemplo.com')
            ->and(json_encode($event->toArray()))->not->toContain('555555');
    }

    foreach (OtpRateLimit::query()->get() as $limit) {
        expect(json_encode($limit->toArray()))->not->toContain('127.0.0.1');
    }
});

test('p0a4 sequential double verify guarantees single consumed challenge and one token', function () {
    // Sequential stand-in for the atomic consume guarantee (not parallel PHP workers).
    // Real concurrent stress under MySQL staging remains pending validation.
    enableAkubicaLoginOtpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('777777'));

    User::factory()->create(['email' => 'race@ejemplo.com']);
    $challengeId = $this->postJson('/api/v1/auth/login/request-code', [
        'email' => 'race@ejemplo.com',
    ])->json('data.challenge_id');

    $first = $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $challengeId,
        'code' => '777777',
    ])->assertOk();

    $second = $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $challengeId,
        'code' => '777777',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'CODE_ALREADY_USED');

    $challenge = OtpChallenge::query()->where('public_id', $challengeId)->first();

    expect($first->json('data.token'))->not->toBeEmpty()
        ->and(PersonalAccessToken::query()->count())->toBe(1)
        ->and($challenge->status())->toBe(OtpChallenge::STATUS_CONSUMED)
        ->and(OtpChallenge::query()->whereNotNull('consumed_at')->count())->toBe(1);
});

test('p0a4 failed expired superseded and exhausted paths never issue tokens', function () {
    enableAkubicaLoginOtpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator(['111111', '222222', '333333']));

    User::factory()->create(['email' => 'notoken@ejemplo.com']);

    $activeId = $this->postJson('/api/v1/auth/login/request-code', [
        'email' => 'notoken@ejemplo.com',
    ])->json('data.challenge_id');

    $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $activeId,
        'code' => '000000',
    ])->assertJsonPath('error.code', 'INVALID_CODE');

    expect(PersonalAccessToken::query()->count())->toBe(0);

    Carbon::setTestNow(now()->addSeconds(60));
    $replacementId = $this->postJson('/api/v1/auth/login/resend-code', [
        'challenge_id' => $activeId,
    ])->assertStatus(202)->json('data.challenge_id');

    $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $activeId,
        'code' => '111111',
    ])->assertUnprocessable();

    expect(PersonalAccessToken::query()->count())->toBe(0);

    OtpChallenge::query()->where('public_id', $replacementId)->update([
        'expires_at' => now()->subMinute(),
    ]);

    $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $replacementId,
        'code' => '222222',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'CODE_EXPIRED');

    expect(PersonalAccessToken::query()->count())->toBe(0);

    Carbon::setTestNow(now()->addMinutes(5));
    config()->set('otp.p0a.policy.max_attempts', 1);

    $freshId = $this->postJson('/api/v1/auth/login/request-code', [
        'email' => 'notoken@ejemplo.com',
    ])->json('data.challenge_id');

    $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $freshId,
        'code' => '000000',
    ]);

    $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $freshId,
        'code' => '333333',
    ]);

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('p0a4 resend rejects client-controlled identity fields', function () {
    enableAkubicaLoginOtpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator(['111111', '999999']));

    User::factory()->create(['email' => 'owner@ejemplo.com']);
    User::factory()->create(['email' => 'attacker@ejemplo.com']);

    $challengeId = $this->postJson('/api/v1/auth/login/request-code', [
        'email' => 'owner@ejemplo.com',
    ])->json('data.challenge_id');

    Carbon::setTestNow(now()->addSeconds(60));

    $response = $this->postJson('/api/v1/auth/login/resend-code', [
        'challenge_id' => $challengeId,
        'email' => 'attacker@ejemplo.com',
        'user_id' => 999999,
        'destination' => 'attacker@ejemplo.com',
        'purpose' => 'akubica_register',
        'subject' => 'attacker@ejemplo.com',
        'context' => 'hijack',
    ])->assertStatus(202);

    $newId = $response->json('data.challenge_id');
    $newChallenge = OtpChallenge::query()->where('public_id', $newId)->first();

    expect($newChallenge->purpose)->toBe('akubica_login')
        ->and($newChallenge->subject_key)->toBe('owner@ejemplo.com')
        ->and($response->json('data'))->not->toHaveKey('email')
        ->and(json_encode($response->json()))->not->toContain('attacker@ejemplo.com');
});

test('p0a4 verify ignores client email and binds identity from challenge', function () {
    enableAkubicaLoginOtpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('888888'));

    $owner = User::factory()->create(['email' => 'dueno@ejemplo.com']);
    User::factory()->create(['email' => 'otro@ejemplo.com']);

    $challengeId = $this->postJson('/api/v1/auth/login/request-code', [
        'email' => 'dueno@ejemplo.com',
    ])->json('data.challenge_id');

    $verify = $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $challengeId,
        'code' => '888888',
        'email' => 'otro@ejemplo.com',
    ])->assertOk();

    expect($verify->json('data.user.email'))->toBe('dueno@ejemplo.com')
        ->and($verify->json('data.user.id'))->toBe($owner->id);
});
