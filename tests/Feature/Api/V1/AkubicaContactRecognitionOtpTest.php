<?php

use App\Models\AccountContext;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\Api\V1\Auth\AccountContextTokenService;
use App\Services\Api\V1\Auth\ContactRecognitionContext;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Tests\Support\Otp\FakeOtpCodeGenerator;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00'));
    disableAllAkubicaOtpFeatures();
    enableLoginOtpWithFakeDelivery();
    config()->set('otp.p0a.account_context.ttl_minutes', 10);
    $this->app->instance(\App\Contracts\Otp\OtpCodeGenerator::class, new FakeOtpCodeGenerator('123456'));
});

afterEach(function () {
    Carbon::setTestNow();
    disableAllAkubicaOtpFeatures();
});

function contactUser(string $phone = '5512345678', array $attributes = []): User
{
    return User::factory()->withRegularCustomer()->create(array_merge([
        'phone' => $phone,
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
        'name' => 'Ada Lovelace',
    ], $attributes));
}

function contactPayload(string $phone = '5512345678', array $extra = []): array
{
    return array_merge([
        'phone' => $phone,
        'conversation_id' => 'conversation-001',
        'channel_event_id' => 'event-001',
        'channel' => 'whatsapp',
        'personalization' => ['allowed' => true],
    ], $extra);
}

function requestContactChallenge(array $payload): string
{
    return test()->postJson('/api/v1/auth/login/request-code', $payload)
        ->assertStatus(202)
        ->json('data.challenge_id');
}

test('does not enumerate nonexistent, unverified, inactive, or ambiguous contacts', function () {
    $unknown = $this->postJson('/api/v1/auth/login/request-code', contactPayload('5599999999'))
        ->assertStatus(202)->json('data');

    $unverified = contactUser('5511111111', ['phone_verified_at' => null]);
    $unverifiedResponse = $this->postJson('/api/v1/auth/login/request-code', contactPayload('5511111111'))
        ->assertStatus(202)->json('data');

    $inactive = User::factory()->withVerifiedPhone()->create([
        'phone' => '5522222222',
        'phone_country' => 'MX',
    ]);
    $inactiveResponse = $this->postJson('/api/v1/auth/login/request-code', contactPayload('5522222222'))
        ->assertStatus(202)->json('data');

    expect($unknown)->toHaveKeys(['requires_otp', 'challenge_id', 'purpose', 'channel', 'destination_masked', 'expires_at', 'resend_available_at'])
        ->and($unverifiedResponse)->toHaveKeys(array_keys($unknown))
        ->and($inactiveResponse)->toHaveKeys(array_keys($unknown))
        ->and(OtpChallenge::query()->count())->toBe(0)
        ->and($unverified->exists)->toBeTrue()
        ->and($inactive->exists)->toBeTrue();
});

test('contextual login verifies OTP, returns only allowed first name, and never exposes context token', function () {
    $user = contactUser();
    $start = $this->postJson('/api/v1/auth/login/request-code', contactPayload())
        ->assertStatus(202)
        ->assertJsonPath('data.personalization.allowed', true)
        ->assertJsonPath('data.personalization.first_name', 'Ada');
    $challengeId = $start->json('data.challenge_id');

    $preContext = AccountContext::query()->where('user_id', $user->id)->sole();
    expect($preContext->verified_at)->toBeNull()
        ->and($preContext->otp_challenge_id)->toBe(
            OtpChallenge::query()->where('public_id', $challengeId)->value('id')
        );

    $response = $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $challengeId,
        'code' => '123456',
    ])->assertOk();

    $response->assertJsonPath('data.otp_verified', true)
        ->assertJsonPath('data.personalization.allowed', true)
        ->assertJsonPath('data.personalization.first_name', 'Ada')
        ->assertJsonMissingPath('data.token')
        ->assertJsonMissingPath('data.account_context_token')
        ->assertJsonMissingPath('data.user');

    expect(AccountContext::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(AccountContext::query()->where('user_id', $user->id)->sole()->fresh()->verified_at)->not->toBeNull()
        ->and(json_encode($response->json()))->not->toContain('account_context_token');
});

test('contextual login suppresses personalization when not allowed', function () {
    contactUser();
    $challengeId = requestContactChallenge(contactPayload(extra: [
        'personalization' => ['allowed' => false],
    ]));

    $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $challengeId,
        'code' => '123456',
    ])->assertOk()
        ->assertJsonPath('data.personalization.allowed', false)
        ->assertJsonMissingPath('data.personalization.first_name');
});

test('context token is bound to phone, conversation, channel, audience, and expiration', function () {
    $user = contactUser();
    $service = app(AccountContextTokenService::class);
    $context = new ContactRecognitionContext('conversation-001', 'event-001', 'whatsapp', true);
    $challenge = OtpChallenge::factory()->create([
        'user_id' => $user->id,
        'subject_key' => 'MX|5215512345678',
        'purpose' => 'akubica_login',
        'channel' => 'sms',
    ]);

    $phoneHash = hash_hmac('sha256', 'MX|5215512345678', (string) config('app.key'));
    $binding = $context->binding((string) config('app.key'));
    $token = $service->issuePreOtp($user, $phoneHash, $binding, $challenge->id);
    $phoneHash = hash_hmac('sha256', 'MX|5212345678', (string) config('app.key'));

    expect($service->resolvePreOtp($token, hash_hmac('sha256', 'MX|5215512345678', (string) config('app.key')), $binding)?->id)->toBe($user->id)
        ->and($service->resolve($token, hash_hmac('sha256', 'MX|5215512345678', (string) config('app.key')), $binding))->toBeNull()
        ->and($service->elevateForChallenge($challenge->id, $user))->toBeTrue()
        ->and($service->resolve($token, hash_hmac('sha256', 'MX|5215512345678', (string) config('app.key')), $binding)?->id)->toBe($user->id)
        ->and($service->resolve($token, $phoneHash, $context->binding((string) config('app.key'))))->toBeNull()
        ->and($service->resolve($token, hash_hmac('sha256', 'MX|5215512345678', (string) config('app.key')), (new ContactRecognitionContext('conversation-002', 'event-001', 'whatsapp', true))->binding((string) config('app.key'))))->toBeNull()
        ->and($service->resolve($token, hash_hmac('sha256', 'MX|5215512345678', (string) config('app.key')), (new ContactRecognitionContext('conversation-001', 'event-001', 'sms', true))->binding((string) config('app.key'))))->toBeNull();

    Carbon::setTestNow(now()->addMinutes(11));
    expect($service->resolve($token, hash_hmac('sha256', 'MX|5215512345678', (string) config('app.key')), $context->binding((string) config('app.key'))))->toBeNull();
});

test('account context token is not accepted as Bearer and private access remains blocked before OTP', function () {
    $this->getJson('/api/v1/memberships/status')->assertUnauthorized();
    $this->withHeader('Authorization', 'Bearer opaque-internal-context-token')
        ->getJson('/api/v1/memberships/status')
        ->assertUnauthorized();
});

test('contact recognition context fields must be supplied together', function () {
    $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '5512345678',
        'conversation_id' => 'conversation-001',
    ])->assertStatus(422)
        ->assertJsonPath('error.fields.channel_event_id.0', 'El contexto conversacional requiere todos sus identificadores.')
        ->assertJsonPath('error.fields.channel.0', 'El contexto conversacional requiere todos sus identificadores.');
});

test('unique phone constraint prevents multiple active account matches', function () {
    $first = contactUser('5533333333');

    expect(fn () => contactUser('5533333333'))->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class)
        ->and(Schema::hasColumn('users', 'phone'))->toBeTrue()
        ->and($first->phone)->not->toBeNull();
});
