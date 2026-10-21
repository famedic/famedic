<?php

use App\Models\AccountContext;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\Otp\FakeOtpCodeGenerator;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00'));
    enableLoginOtpWithFakeDelivery();
    config()->set('atix.sandbox.keys', ['sandbox-test' => 'sandbox-secret']);
    config()->set('api_v1.audit.enabled', true);
    $this->app->instance(\App\Contracts\Otp\OtpCodeGenerator::class, new FakeOtpCodeGenerator('123456'));
});

afterEach(function () { Carbon::setTestNow(); disableAllAkubicaOtpFeatures(); });

function atixUser(): User
{
    return User::factory()->withRegularCustomer()->create([
        'phone' => '5512345678', 'phone_country' => 'MX',
        'phone_verified_at' => now(), 'name' => 'Ada Lovelace',
    ]);
}

function atixPayload(array $overrides = []): array
{
    return array_merge([
        'phone' => '+525512345678', 'conversation_id' => 'conversation-atix-1',
        'channel_event_id' => 'event-atix-1', 'channel' => 'whatsapp', 'audience' => 'leo',
        'personalization' => ['allowed' => true],
    ], $overrides);
}

function atixHeaders(array $payload, string $nonce = 'nonce-atix-001', string $idempotency = 'idem-atix-001', ?string $timestamp = null): array
{
    $timestamp ??= (string) now()->timestamp;
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $canonical = implode("\n", ['POST', '/api/v1/integrations/atix/phone-lookups', $timestamp, $nonce, hash('sha256', $body)]);
    return [
        'X-Integration-Key-Id' => 'sandbox-test', 'X-Timestamp' => $timestamp, 'X-Nonce' => $nonce,
        'X-Signature' => hash_hmac('sha256', $canonical, 'sandbox-secret'),
        'X-Correlation-ID' => 'corr-atix-001', 'Idempotency-Key' => $idempotency,
    ];
}

test('recognizes an eligible contact without exposing internal context', function () {
    atixUser();
    $payload = atixPayload();
    $response = $this->withHeaders(atixHeaders($payload))->postJson('/api/v1/integrations/atix/phone-lookups', $payload);
    $response->assertStatus(202)->assertHeader('X-Correlation-ID', 'corr-atix-001')
        ->assertJsonPath('data.personalization.first_name', 'Ada')
        ->assertJsonMissingPath('data.account_context_token');
    expect(AccountContext::query()->where('audience', 'leo')->count())->toBe(1);
});

test('rejects invalid signatures and expired timestamps without creating context', function () {
    $payload = atixPayload();
    $this->withHeaders(array_merge(atixHeaders($payload), ['X-Signature' => str_repeat('0', 64)]))
        ->postJson('/api/v1/integrations/atix/phone-lookups', $payload)->assertUnauthorized()
        ->assertJsonPath('error.retryable', false);
    $this->withHeaders(atixHeaders($payload, 'nonce-atix-002', 'idem-atix-002', (string) now()->subMinutes(6)->timestamp))
        ->postJson('/api/v1/integrations/atix/phone-lookups', $payload)->assertUnauthorized();
    expect(AccountContext::query()->count())->toBe(0);
});

test('rejects nonce replay and invalid channel or audience', function () {
    $payload = atixPayload();
    $this->withHeaders(atixHeaders($payload))->postJson('/api/v1/integrations/atix/phone-lookups', $payload)->assertStatus(202);
    $this->withHeaders(atixHeaders($payload))->postJson('/api/v1/integrations/atix/phone-lookups', $payload)->assertStatus(409);
    $bad = atixPayload([
        'channel' => 'sms',
        'channel_event_id' => 'event-atix-invalid-channel',
    ]);
    $this->withHeaders(atixHeaders($bad, 'nonce-atix-003', 'idem-atix-003'))->postJson('/api/v1/integrations/atix/phone-lookups', $bad)->assertStatus(422);
});

test('replays same idempotent operation and conflicts on changed payload', function () {
    atixUser();
    $payload = atixPayload();
    $first = $this->withHeaders(atixHeaders($payload, 'nonce-atix-004'))->postJson('/api/v1/integrations/atix/phone-lookups', $payload)->assertStatus(202);
    $second = $this->withHeaders(atixHeaders($payload, 'nonce-atix-005'))->postJson('/api/v1/integrations/atix/phone-lookups', $payload)->assertStatus(202);
    $second->assertHeader('Idempotency-Replayed', 'true')->assertExactJson($first->json());
    $changed = atixPayload(['personalization' => ['allowed' => false]]);
    $this->withHeaders(atixHeaders($changed, 'nonce-atix-006'))->postJson('/api/v1/integrations/atix/phone-lookups', $changed)->assertStatus(409);
    expect(AccountContext::query()->count())->toBe(1);
});

test('keeps anti-enumeration shape for invalid and unverified contacts', function () {
    $unknown = atixPayload(['phone' => '+525599999999']);
    $unverified = User::factory()->withRegularCustomer()->create(['phone' => '5511111111', 'phone_country' => 'MX', 'phone_verified_at' => null]);
    $a = $this->withHeaders(atixHeaders($unknown, 'nonce-atix-007', 'idem-atix-007'))->postJson('/api/v1/integrations/atix/phone-lookups', $unknown)->assertStatus(202)->json('data');
    $bPayload = atixPayload(['phone' => '+525511111111', 'channel_event_id' => 'event-atix-2']);
    $b = $this->withHeaders(atixHeaders($bPayload, 'nonce-atix-008', 'idem-atix-008'))->postJson('/api/v1/integrations/atix/phone-lookups', $bPayload)->assertStatus(202)->json('data');
    expect(array_keys($a))->toBe(array_keys($b))->and($unverified->exists)->toBeTrue();
});

test('persists and replays the anti-enumeration response as a serialized array', function () {
    $payload = atixPayload(['phone' => '+525599999999', 'channel_event_id' => 'event-anti-replay']);
    $first = $this->withHeaders(atixHeaders($payload, 'nonce-anti-replay-1', 'idem-anti-replay'))
        ->postJson('/api/v1/integrations/atix/phone-lookups', $payload)
        ->assertStatus(202);
    $second = $this->withHeaders(atixHeaders($payload, 'nonce-anti-replay-2', 'idem-anti-replay'))
        ->postJson('/api/v1/integrations/atix/phone-lookups', $payload)
        ->assertStatus(202)
        ->assertHeader('Idempotency-Replayed', 'true');

    expect($second->json())->toBe($first->json());
});

test('rejects malformed idempotency keys deterministically before lookup', function () {
    $payload = atixPayload();
    $headers = atixHeaders($payload, 'nonce-atix-invalid-key', 'bad key');
    $this->withHeaders($headers)
        ->postJson('/api/v1/integrations/atix/phone-lookups', $payload)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'ATIX_IDEMPOTENCY_INVALID')
        ->assertJsonPath('error.retryable', false);
});

test('does not expose sensitive values in ATIX responses', function () {
    $payload = atixPayload();
    $response = $this->withHeaders(atixHeaders($payload, 'nonce-atix-sensitive', 'idem-atix-sensitive'))
        ->postJson('/api/v1/integrations/atix/phone-lookups', $payload);
    expect(json_encode($response->json()))
        ->not->toContain('sandbox-secret')
        ->not->toContain('account_context_token')
        ->not->toContain('123456');
});
