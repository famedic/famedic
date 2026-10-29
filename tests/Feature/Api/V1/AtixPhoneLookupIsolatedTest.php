<?php

namespace Tests\Feature\Api\V1;

use App\Models\AccountContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Otp\FakeOtpCodeGenerator;
use Tests\TestCase;

/**
 * ATIX Feature suite against the already-prepared famedic_testing schema.
 * It deliberately does not migrate, refresh, truncate, or transact the database.
 * Prepare it outside PHPUnit only when required with:
 * docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=famedic_testing app php artisan migrate:fresh --env=testing --database=mysql --force
 */
final class AtixPhoneLookupIsolatedTest extends TestCase
{
    private string $runId;
    private string $clientIp;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();

        $this->runId = strtolower(substr(preg_replace('/[^a-z0-9]/i', '', uniqid('', true)), 0, 12));
        $this->clientIp = '10.'.random_int(1, 254).'.'.random_int(1, 254).'.'.random_int(1, 254);
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00'));
        enableLoginOtpWithFakeDelivery();
        config()->set('atix.sandbox.keys', ['sandbox-test' => 'sandbox-secret']);
        config()->set('api_v1.audit.enabled', true);
        $this->app->instance(\App\Contracts\Otp\OtpCodeGenerator::class, new FakeOtpCodeGenerator('123456'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        disableAllAkubicaOtpFeatures();
        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function it_recognizes_an_eligible_contact_without_exposing_context(): void
    {
        $user = $this->atixUser();
        $payload = $this->payload(['phone' => '+52'.$user->getRawOriginal('phone')]);

        $this->atixPost($payload, $this->headers($payload))
            ->assertStatus(202)
            ->assertHeader('X-Correlation-ID', 'corr-'.$this->runId)
            ->assertJsonPath('data.recognition_status', 'recognized_unverified')
            ->assertJsonPath('data.personalization.first_name', 'Ada')
            ->assertJsonMissingPath('data.account_context_token')
            ->assertJsonMissingPath('data.challenge_id');

        $this->assertSame(1, AccountContext::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function it_rejects_invalid_signature_and_expired_timestamp(): void
    {
        $payload = $this->payload();
        $this->atixPost($payload, array_merge($this->headers($payload), ['X-Signature' => str_repeat('0', 64)]))
            ->assertUnauthorized()->assertJsonPath('error.retryable', false);

        $this->atixPost($payload, $this->headers($payload, 'nonce-'.$this->runId.'-expired', 'idem-'.$this->runId.'-expired', (string) now()->subMinutes(6)->timestamp))
            ->assertUnauthorized();
    }

    #[Test]
    public function it_rejects_nonce_replay_and_invalid_channel(): void
    {
        $user = $this->atixUser();
        $payload = $this->payload(['phone' => '+52'.$user->getRawOriginal('phone')]);
        $this->atixPost($payload, $this->headers($payload))->assertStatus(202);
        $this->atixPost($payload, $this->headers($payload))->assertStatus(409);

        $bad = $this->payload([
            'channel' => 'sms',
            'channel_event_id' => 'event-atix-invalid-channel-'.$this->runId,
        ]);
        $this->atixPost($bad, $this->headers($bad, 'nonce-'.$this->runId.'-bad-channel', 'idem-'.$this->runId.'-bad-channel'))
            ->assertStatus(422);
    }

    #[Test]
    public function it_replays_and_conflicts_idempotent_payloads(): void
    {
        $user = $this->atixUser();
        $payload = $this->payload(['phone' => '+52'.$user->getRawOriginal('phone')]);
        $first = $this->atixPost($payload, $this->headers($payload, 'nonce-'.$this->runId.'-first'))->assertStatus(202);
        $second = $this->atixPost($payload, $this->headers($payload, 'nonce-'.$this->runId.'-second'))
            ->assertStatus(202)->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->json(), $second->json());

        $changed = $this->payload(['personalization' => ['allowed' => false]]);
        $this->atixPost($changed, $this->headers($changed, 'nonce-'.$this->runId.'-changed'))->assertStatus(409);
    }

    #[Test]
    public function it_preserves_anti_enumeration_for_unknown_and_unverified_contacts(): void
    {
        $unknown = $this->payload(['phone' => '+525599'.str_pad((string) random_int(100000, 999999), 6, '0'), 'channel_event_id' => 'event-'.$this->runId.'-unknown']);
        $unknownData = $this->atixPost($unknown, $this->headers($unknown, 'nonce-'.$this->runId.'-unknown', 'idem-'.$this->runId.'-unknown'))
            ->assertStatus(202)->json('data');

        $unverifiedPhone = '551'.str_pad((string) random_int(1000000, 9999999), 7, '0');
        User::factory()->withRegularCustomer()->create(['phone' => $unverifiedPhone, 'phone_country' => 'MX', 'phone_verified_at' => null]);
        $unverified = $this->payload(['phone' => '+52'.$unverifiedPhone, 'channel_event_id' => 'event-'.$this->runId.'-unverified']);
        $unverifiedData = $this->atixPost($unverified, $this->headers($unverified, 'nonce-'.$this->runId.'-unverified', 'idem-'.$this->runId.'-unverified'))
            ->assertStatus(202)->json('data');

        $this->assertSame(array_keys($unknownData), array_keys($unverifiedData));
    }

    #[Test]
    public function it_persists_and_replays_anti_enumeration_as_json(): void
    {
        $payload = $this->payload(['phone' => '+525599'.str_pad((string) random_int(100000, 999999), 6, '0'), 'channel_event_id' => 'event-'.$this->runId.'-anti-replay']);
        $first = $this->atixPost($payload, $this->headers($payload, 'nonce-'.$this->runId.'-anti-1', 'idem-'.$this->runId.'-anti'))
            ->assertStatus(202);
        $second = $this->atixPost($payload, $this->headers($payload, 'nonce-'.$this->runId.'-anti-2', 'idem-'.$this->runId.'-anti'))
            ->assertStatus(202)->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json(), $second->json());
    }

    #[Test]
    public function it_rejects_invalid_idempotency_key_and_does_not_expose_sensitive_values(): void
    {
        $payload = $this->payload();
        $this->atixPost($payload, $this->headers($payload, 'nonce-'.$this->runId.'-invalid-key', 'bad key'))
            ->assertStatus(422)->assertJsonPath('error.code', 'ATIX_IDEMPOTENCY_INVALID');

        $sensitive = $this->payload(['channel_event_id' => 'event-'.$this->runId.'-sensitive']);
        $response = $this->atixPost($sensitive, $this->headers($sensitive, 'nonce-'.$this->runId.'-sensitive', 'idem-'.$this->runId.'-sensitive'));
        $json = json_encode($response->json());
        $this->assertStringNotContainsString('sandbox-secret', $json);
        $this->assertStringNotContainsString('account_context_token', $json);
        $this->assertStringNotContainsString('123456', $json);
    }

    private function atixUser(): User
    {
        $phone = '551'.str_pad((string) random_int(1000000, 9999999), 7, '0');
        return User::factory()->withRegularCustomer()->create([
            'phone' => $phone, 'phone_country' => 'MX', 'phone_verified_at' => now(), 'name' => 'Ada Lovelace',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'phone' => '+525599'.$this->numericRunPhone(), 'conversation_id' => 'conversation-'.$this->runId,
            'channel_event_id' => 'event-'.$this->runId, 'channel' => 'whatsapp', 'audience' => 'leo',
            'personalization' => ['allowed' => true],
        ], $overrides);
    }

    private function headers(array $payload, ?string $nonce = null, ?string $idempotency = null, ?string $timestamp = null): array
    {
        $nonce ??= 'nonce-'.$this->runId.'-default';
        $idempotency ??= 'idem-'.$this->runId.'-default';
        $timestamp ??= (string) now()->timestamp;
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $canonical = implode("\n", ['POST', $this->uri(), $timestamp, $nonce, hash('sha256', $body)]);

        return [
            'X-Integration-Key-Id' => 'sandbox-test', 'X-Timestamp' => $timestamp, 'X-Nonce' => $nonce,
            'X-Signature' => hash_hmac('sha256', $canonical, 'sandbox-secret'),
            'X-Correlation-ID' => 'corr-'.$this->runId, 'Idempotency-Key' => $idempotency,
        ];
    }

    private function uri(): string
    {
        return '/api/v1/integrations/atix/phone-lookups';
    }

    private function atixPost(array $payload, array $headers)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->withHeaders($headers)
            ->postJson($this->uri(), $payload);
    }

    private function numericRunPhone(): string
    {
        return str_pad((string) (hexdec(substr(md5($this->runId), 0, 7)) % 10000000), 7, '0', STR_PAD_LEFT);
    }
}
