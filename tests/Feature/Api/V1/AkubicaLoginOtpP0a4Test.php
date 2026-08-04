<?php

use App\Contracts\Otp\OtpCodeGenerator;
use App\Models\Customer;
use App\Models\OtpAbuseEvent;
use App\Models\OtpChallenge;
use App\Models\OtpCode;
use App\Models\OtpDeliveryOperation;
use App\Models\OtpRateLimit;
use App\Models\User;
use App\Notifications\Api\V1\Auth\AkubicaOtpNotification;
use App\Services\Otp\Delivery\FakeOtpDeliveryProvider;
use App\Services\Otp\Delivery\OtpDeliveryResultClass;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\Support\Otp\FakeOtpCodeGenerator;

function enableAkubicaLoginOtpFlags(): void
{
    enableLoginOtpWithFakeDelivery();
}

function disableAkubicaLoginOtpFlags(): void
{
    disableAllAkubicaOtpFeatures();
}

/**
 * @param  array<string, mixed>  $attrs
 */
function loginOtpUser(string $nationalPhone, array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'phone' => $nationalPhone,
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ], $attrs));
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
});

test('p0a4 flags off does not create challenges for nonexistent email', function () {
    $this->postJson('/api/v1/auth/login/request-code', ['email' => 'nadie@ejemplo.com'])
        ->assertOk();

    expect(OtpChallenge::query()->count())->toBe(0);
});

test('p0a4 resend is disabled when login otp flag is off', function () {
    $this->postJson('/api/v1/auth/login/resend-code', [
        'challenge_id' => '00000000-0000-4000-8000-000000000001',
    ])->assertStatus(503)
        ->assertJsonPath('error.code', 'FEATURE_DISABLED');
});

test('p0a4 request returns 202 challenge with sms delivery without token', function () {
    enableAkubicaLoginOtpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('123456'));

    $user = loginOtpUser('5512345678', ['email' => 'otp.login@ejemplo.com']);

    $response = $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '+525512345678',
    ])->assertStatus(202)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.requires_otp', true)
        ->assertJsonPath('data.purpose', 'akubica_login')
        ->assertJsonPath('data.channel', 'sms')
        ->assertJsonPath('data.destination_masked', '***5678');

    $json = $response->json('data');
    expect($json)->toHaveKeys(['challenge_id', 'destination_masked', 'expires_at', 'resend_available_at'])
        ->and($json)->not->toHaveKey('token')
        ->and(json_encode($json))->not->toContain('123456')
        ->and(json_encode($json))->not->toContain('5512345678')
        ->and(json_encode($json))->not->toContain('+525512345678');

    Notification::assertNothingSent();
    expect(OtpChallenge::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(OtpDeliveryOperation::query()->where('purpose', 'akubica_login')->count())->toBe(1)
        ->and(OtpDeliveryOperation::query()->where('primary_channel', 'sms')->count())->toBe(1)
        ->and(OtpCode::query()->count())->toBe(0)
        ->and(PersonalAccessToken::query()->count())->toBe(0)
        ->and(count(app(FakeOtpDeliveryProvider::class)->sent))->toBe(1)
        ->and(app(FakeOtpDeliveryProvider::class)->sent[0]['channel'])->toBe('sms')
        ->and(app(FakeOtpDeliveryProvider::class)->sent[0]['purpose'])->toBe('akubica_login');

    $challenge = OtpChallenge::query()->where('user_id', $user->id)->first();
    expect(Hash::check('123456', $challenge->code_hash))->toBeTrue()
        ->and($challenge->code_hash)->not->toBe('123456')
        ->and($challenge->channel)->toBe('sms')
        ->and($challenge->subject_type)->toBe('phone');
});

test('p0a4 request for nonexistent phone returns decoy 202 without persistence or sms', function () {
    enableAkubicaLoginOtpFlags();

    $response = $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '+525511111111',
    ])->assertStatus(202)
        ->assertJsonPath('data.requires_otp', true)
        ->assertJsonPath('data.channel', 'sms')
        ->assertJsonPath('data.destination_masked', '***1111');

    $challengeId = $response->json('data.challenge_id');
    expect($challengeId)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');

    Notification::assertNothingSent();
    expect(OtpChallenge::query()->count())->toBe(0)
        ->and(OtpDeliveryOperation::query()->count())->toBe(0)
        ->and(count(app(FakeOtpDeliveryProvider::class)->sent))->toBe(0)
        ->and(OtpRateLimit::query()->count())->toBe(0)
        ->and(OtpAbuseEvent::query()->count())->toBe(0)
        ->and(PersonalAccessToken::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0);
});

test('p0a4 decoy and existing user start responses share public contract shape', function () {
    enableAkubicaLoginOtpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('123456'));

    loginOtpUser('5512345678', ['email' => 'mismo.dominio@ejemplo.com']);

    $existing = $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '5512345678',
    ])->assertStatus(202)->json('data');

    $decoy = $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '5599999999',
    ])->assertStatus(202)->json('data');

    $keys = ['requires_otp', 'challenge_id', 'purpose', 'channel', 'destination_masked', 'expires_at', 'resend_available_at'];
    expect(array_keys($existing))->toEqualCanonicalizing($keys)
        ->and(array_keys($decoy))->toEqualCanonicalizing($keys)
        ->and($existing['requires_otp'])->toBeTrue()
        ->and($decoy['requires_otp'])->toBeTrue()
        ->and($existing['purpose'])->toBe($decoy['purpose'])
        ->and($existing['channel'])->toBe('sms')
        ->and($decoy['channel'])->toBe('sms')
        ->and($existing['destination_masked'])->toBe('***5678')
        ->and($decoy['destination_masked'])->toBe('***9999')
        ->and($existing)->not->toHaveKey('token')
        ->and($decoy)->not->toHaveKey('token');

    $challenge = OtpChallenge::query()->where('public_id', $existing['challenge_id'])->first();
    expect($challenge)->not->toBeNull()
        ->and(OtpChallenge::query()->where('public_id', $decoy['challenge_id'])->exists())->toBeFalse();
});

test('p0a4 verify and resend of never-issued uuid stay NO_ACTIVE_CODE', function () {
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
});

test('p0a4 issued decoy verify and resend match real public contracts across lifecycle', function () {
    enableAkubicaLoginOtpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator(['111111', '222222', '333333']));

    loginOtpUser('5512345678', ['email' => 'real.ciclo@ejemplo.com']);

    $realStart = $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '5512345678',
    ])->assertStatus(202);

    $decoyStart = $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '5598765432',
    ])->assertStatus(202);

    $realId = $realStart->json('data.challenge_id');
    $decoyId = $decoyStart->json('data.challenge_id');

    expect(OtpChallenge::query()->count())->toBe(1)
        ->and(OtpChallenge::query()->where('public_id', $decoyId)->exists())->toBeFalse()
        ->and(OtpRateLimit::query()->where('bucket_type', 'identity')->count())->toBe(1);

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
        ->and($realWrong->json('error.message'))->toBe($decoyWrong->json('error.message'));

    $realResendCool = $this->postJson('/api/v1/auth/login/resend-code', [
        'challenge_id' => $realId,
    ])->assertStatus(429);

    $decoyResendCool = $this->postJson('/api/v1/auth/login/resend-code', [
        'challenge_id' => $decoyId,
    ])->assertStatus(429);

    expect($realResendCool->json('error.code'))->toBe('OTP_COOLDOWN')
        ->and($decoyResendCool->json('error.code'))->toBe('OTP_COOLDOWN');

    Carbon::setTestNow(now()->addSeconds(60));

    $realResendOk = $this->postJson('/api/v1/auth/login/resend-code', [
        'challenge_id' => $realId,
    ])->assertStatus(202);

    $decoyResendOk = $this->postJson('/api/v1/auth/login/resend-code', [
        'challenge_id' => $decoyId,
    ])->assertStatus(202);

    $realId2 = $realResendOk->json('data.challenge_id');
    $decoyId2 = $decoyResendOk->json('data.challenge_id');

    expect($realId2)->not->toBe($realId)
        ->and($decoyId2)->not->toBe($decoyId)
        ->and($realResendOk->json('data.channel'))->toBe('sms')
        ->and($decoyResendOk->json('data.channel'))->toBe('sms');

    $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $decoyId2,
        'code' => '333333',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVALID_CODE');

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('p0a4 login otp without anti abuse returns configuration error', function () {
    config()->set('otp.p0a.flags.akubica_login_enabled', true);
    config()->set('otp.p0a.flags.anti_abuse_enabled', false);
    config()->set('otp.p0a.flags.sms_delivery_enabled', true);

    loginOtpUser('5512345678', ['email' => 'cfg@ejemplo.com']);

    $response = $this->postJson('/api/v1/auth/login/request-code', ['phone' => '5512345678'])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'OTP_CONFIGURATION_INVALID');

    $body = json_encode($response->json());
    expect($body)->not->toContain('anti_abuse')
        ->and($body)->not->toContain('OTP_P0A')
        ->and(OtpChallenge::query()->count())->toBe(0);
});

test('p0a4 login otp without sms delivery returns configuration error', function () {
    config()->set('otp.p0a.flags.akubica_login_enabled', true);
    config()->set('otp.p0a.flags.anti_abuse_enabled', true);
    config()->set('otp.p0a.flags.sms_delivery_enabled', false);

    loginOtpUser('5512345678', ['email' => 'nosms@ejemplo.com']);

    $this->postJson('/api/v1/auth/login/request-code', ['phone' => '5512345678'])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'OTP_CONFIGURATION_INVALID');

    expect(OtpChallenge::query()->count())->toBe(0)
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

test('p0a4 verify issues sanctum token for existing user and customer without creating duplicates', function () {
    enableAkubicaLoginOtpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('654321'));

    $user = User::factory()->withRegularCustomer()->create([
        'email' => 'verify@ejemplo.com',
        'name' => 'Paciente',
        'paternal_lastname' => 'Prueba',
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);
    $customer = $user->customer;
    $usersBefore = User::query()->count();
    $customersBefore = Customer::query()->count();

    $start = $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '+525512345678',
    ])->assertStatus(202);

    $challengeId = $start->json('data.challenge_id');

    $verify = $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $challengeId,
        'code' => '654321',
    ])->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.email', 'verify@ejemplo.com')
        ->assertJsonPath('data.user.id', $user->id);

    $token = $verify->json('data.token');
    $expiresIn = $verify->json('data.expires_in');
    $sanctumMinutes = (int) config('sanctum.expiration');

    expect($token)->not->toBeEmpty()
        ->and($sanctumMinutes)->toBe(1440)
        ->and($expiresIn)->toBe($sanctumMinutes * 60)
        ->and(User::query()->count())->toBe($usersBefore)
        ->and(Customer::query()->count())->toBe($customersBefore)
        ->and(Customer::query()->where('id', $customer->id)->where('user_id', $user->id)->exists())->toBeTrue();

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

    loginOtpUser('5512345678', ['email' => 'wrong@ejemplo.com']);
    $challengeId = $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '5512345678',
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

    loginOtpUser('5512345678', ['email' => 'max@ejemplo.com']);
    $challengeId = $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '5512345678',
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

    loginOtpUser('5512345678', ['email' => 'cool@ejemplo.com']);

    $this->postJson('/api/v1/auth/login/request-code', ['phone' => '5512345678'])
        ->assertStatus(202);

    $response = $this->postJson('/api/v1/auth/login/request-code', ['phone' => '5512345678'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'OTP_COOLDOWN')
        ->assertJsonPath('error.details.retry_after', 60);

    expect($response->headers->get('Retry-After'))->toBe('60')
        ->and(OtpChallenge::query()->count())->toBe(1);
});

test('p0a4 resend after cooldown invalidates previous challenge and sends new sms', function () {
    enableAkubicaLoginOtpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator(['111111', '999999']));

    loginOtpUser('5512345678', ['email' => 'resend@ejemplo.com']);

    $firstId = $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '5512345678',
    ])->json('data.challenge_id');

    expect(count(app(FakeOtpDeliveryProvider::class)->sent))->toBe(1);

    Carbon::setTestNow(now()->addSeconds(60));

    $second = $this->postJson('/api/v1/auth/login/resend-code', [
        'challenge_id' => $firstId,
    ])->assertStatus(202);

    $secondId = $second->json('data.challenge_id');
    expect($secondId)->not->toBe($firstId)
        ->and(OtpChallenge::query()->where('public_id', $firstId)->first()->status())
        ->toBe(OtpChallenge::STATUS_INVALIDATED)
        ->and(count(app(FakeOtpDeliveryProvider::class)->sent))->toBe(2);

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

test('p0a4 vonage failure returns DELIVERY_FAILED without token', function () {
    enableAkubicaLoginOtpFlags();
    app(FakeOtpDeliveryProvider::class)->failAlwaysWith(OtpDeliveryResultClass::TransportError);
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('444444'));

    loginOtpUser('5512345678', ['email' => 'fail@ejemplo.com']);

    $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '5512345678',
    ])->assertStatus(503)
        ->assertJsonPath('error.code', 'DELIVERY_FAILED');

    expect(PersonalAccessToken::query()->count())->toBe(0)
        ->and(OtpChallenge::query()->whereNull('invalidated_at')->count())->toBe(0);
});

test('p0a4 does not persist otp plaintext or full destination in abuse events or logs', function () {
    enableAkubicaLoginOtpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('555555'));

    $handler = new TestHandler;
    $logger = new class('testing') extends Logger
    {
        /** @param  array<string, mixed>  $context */
        public function shareContext(array $context): void
        {
        }

        /** @return array<string, mixed> */
        public function sharedContext(): array
        {
            return [];
        }
    };
    $logger->pushHandler($handler);
    Log::swap($logger);

    loginOtpUser('5512345678', ['email' => 'secreto@ejemplo.com']);
    $this->postJson('/api/v1/auth/login/request-code', ['phone' => '5512345678'])
        ->assertStatus(202);

    $challenge = OtpChallenge::query()->first();
    expect($challenge->code_hash)->not->toBe('555555')
        ->and(json_encode($challenge->toArray()))->not->toContain('555555');

    foreach (OtpAbuseEvent::query()->get() as $event) {
        expect(json_encode($event->toArray()))->not->toContain('5512345678')
            ->and(json_encode($event->toArray()))->not->toContain('secreto@ejemplo.com')
            ->and(json_encode($event->toArray()))->not->toContain('555555');
    }

    foreach ($handler->getRecords() as $record) {
        $blob = json_encode([
            'message' => $record['message'] ?? '',
            'context' => $record['context'] ?? [],
        ], JSON_THROW_ON_ERROR);
        expect($blob)->not->toContain('555555')
            ->and($blob)->not->toContain('5512345678')
            ->and($blob)->not->toContain('secreto@ejemplo.com');
    }
});

test('p0a4 sequential double verify guarantees single consumed challenge and one token', function () {
    enableAkubicaLoginOtpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('777777'));

    loginOtpUser('5512345678', ['email' => 'race@ejemplo.com']);
    $challengeId = $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '5512345678',
    ])->json('data.challenge_id');

    $first = $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $challengeId,
        'code' => '777777',
    ])->assertOk();

    $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $challengeId,
        'code' => '777777',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'CODE_ALREADY_USED');

    expect($first->json('data.token'))->not->toBeEmpty()
        ->and(PersonalAccessToken::query()->count())->toBe(1)
        ->and(OtpChallenge::query()->whereNotNull('consumed_at')->count())->toBe(1);
});

test('p0a4 failed expired superseded and exhausted paths never issue tokens', function () {
    enableAkubicaLoginOtpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator(['111111', '222222', '333333']));

    loginOtpUser('5512345678', ['email' => 'notoken@ejemplo.com']);

    $activeId = $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '5512345678',
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
        'phone' => '5512345678',
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

    $owner = loginOtpUser('5512345678', ['email' => 'owner@ejemplo.com']);
    loginOtpUser('5587654321', ['email' => 'attacker@ejemplo.com']);

    $challengeId = $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '5512345678',
    ])->json('data.challenge_id');

    Carbon::setTestNow(now()->addSeconds(60));

    $response = $this->postJson('/api/v1/auth/login/resend-code', [
        'challenge_id' => $challengeId,
        'phone' => '5587654321',
        'user_id' => 999999,
        'destination' => '+525587654321',
        'purpose' => 'akubica_register',
        'subject' => 'attacker',
        'context' => 'hijack',
    ])->assertStatus(202);

    $newId = $response->json('data.challenge_id');
    $newChallenge = OtpChallenge::query()->where('public_id', $newId)->first();

    expect($newChallenge->purpose)->toBe('akubica_login')
        ->and($newChallenge->subject_key)->toBe('MX|5512345678')
        ->and($newChallenge->user_id)->toBe($owner->id)
        ->and($response->json('data'))->not->toHaveKey('phone')
        ->and(json_encode($response->json()))->not->toContain('5587654321');
});

test('p0a4 verify ignores client phone and binds identity from challenge', function () {
    enableAkubicaLoginOtpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('888888'));

    $owner = loginOtpUser('5512345678', ['email' => 'dueno@ejemplo.com']);
    loginOtpUser('5587654321', ['email' => 'otro@ejemplo.com']);

    $challengeId = $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '5512345678',
    ])->json('data.challenge_id');

    $verify = $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $challengeId,
        'code' => '888888',
        'phone' => '5587654321',
        'user_id' => 999,
    ])->assertOk();

    expect($verify->json('data.user.email'))->toBe('dueno@ejemplo.com')
        ->and($verify->json('data.user.id'))->toBe($owner->id);
});

test('p0a4 unverified phone is treated as decoy and never authenticates', function () {
    enableAkubicaLoginOtpFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('121212'));

    User::factory()->create([
        'email' => 'unverified@ejemplo.com',
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => null,
    ]);

    $response = $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '5512345678',
    ])->assertStatus(202);

    expect(OtpChallenge::query()->count())->toBe(0)
        ->and(count(app(FakeOtpDeliveryProvider::class)->sent))->toBe(0);

    $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $response->json('data.challenge_id'),
        'code' => '121212',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVALID_CODE');

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('p0a4 register challenge cannot be used for login verify', function () {
    enableAkubicaLoginOtpFlags();

    $user = loginOtpUser('5512345678', ['email' => 'cross@ejemplo.com']);
    $challenge = OtpChallenge::query()->create([
        'public_id' => (string) Illuminate\Support\Str::uuid(),
        'user_id' => $user->id,
        'subject_type' => 'phone',
        'subject_key' => 'MX|5512345678',
        'purpose' => 'akubica_register',
        'channel' => 'sms',
        'destination_normalized' => '+525512345678',
        'destination_masked' => '***5678',
        'code_hash' => Hash::make('123456'),
        'expires_at' => now()->addMinutes(5),
        'failed_attempts' => 0,
        'max_attempts' => 5,
        'send_count' => 1,
        'last_sent_at' => now(),
        'context_type' => 'akubica_register',
        'context_id' => $user->id,
    ]);

    $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $challenge->public_id,
        'code' => '123456',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVALID_CODE');

    expect(PersonalAccessToken::query()->count())->toBe(0);
});
