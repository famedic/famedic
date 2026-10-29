<?php

namespace Tests\Feature\Api\V1;

use App\Models\AccountContext;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\Otp\Delivery\FakeOtpDeliveryProvider;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Atix\AtixSandboxRequestBuilder;
use Tests\TestCase;

/**
 * RC1 fase 1 — phone lookup sin OTP; request-code contextual.
 */
final class AtixPhoneLookupRc1Phase1IsolatedTest extends TestCase
{
    private string $runId;

    private string $clientIp;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();

        $this->runId = strtolower(substr(preg_replace('/[^a-z0-9]/i', '', uniqid('', true)), 0, 10));
        $this->clientIp = '10.'.random_int(1, 254).'.'.random_int(1, 254).'.'.random_int(1, 254);
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00'));
        enableLoginOtpWithFakeDelivery();
        config()->set('atix.sandbox.keys', ['sandbox-test' => 'sandbox-secret']);
        config()->set('otp.p0a.account_context.ttl_minutes', 10);
        app(FakeOtpDeliveryProvider::class)->sent = [];
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
    public function recognized_lookup_does_not_create_otp_or_send_sms(): void
    {
        $user = $this->eligibleUser();
        $payload = $this->lookupPayload(['phone' => '+52'.$user->getRawOriginal('phone')]);
        $challengesBefore = OtpChallenge::query()->count();

        $this->atixPost($payload, $this->lookupHeaders($payload))
            ->assertStatus(202)
            ->assertHeader('X-Correlation-ID', 'corr-'.$this->runId)
            ->assertJsonPath('data.recognition_status', 'recognized_unverified')
            ->assertJsonPath('data.personalization.first_name', 'Ada')
            ->assertJsonMissingPath('data.challenge_id')
            ->assertJsonMissingPath('data.requires_otp');

        $this->assertSame($challengesBefore, OtpChallenge::query()->count());
        $this->assertCount(0, app(FakeOtpDeliveryProvider::class)->sent);
        $this->assertGreaterThanOrEqual(1, AccountContext::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function unknown_lookup_is_anti_enumerated_without_otp(): void
    {
        $payload = $this->lookupPayload(['phone' => '+525588'.random_int(100000, 999999)]);

        $contextsBefore = AccountContext::query()->count();
        $challengesBefore = OtpChallenge::query()->count();

        $this->atixPost($payload, $this->lookupHeaders($payload))
            ->assertStatus(202)
            ->assertJsonPath('data.recognition_status', 'unknown')
            ->assertJsonMissingPath('data.challenge_id');

        $this->assertSame($contextsBefore, AccountContext::query()->count());
        $this->assertSame($challengesBefore, OtpChallenge::query()->count());
    }

    #[Test]
    public function contextual_request_code_after_lookup_creates_challenge_and_sends_sms(): void
    {
        $user = $this->eligibleUser();
        $payload = $this->lookupPayload(['phone' => '+52'.$user->getRawOriginal('phone')]);
        $this->atixPost($payload, $this->lookupHeaders($payload))->assertStatus(202);

        $beforeChallenges = OtpChallenge::query()->count();
        $this->loginPost( $this->loginContextPayload($user))
            ->assertStatus(202)
            ->assertJsonPath('data.requires_otp', true)
            ->assertJsonPath('data.challenge_id', fn ($value) => is_string($value) && $value !== '');

        $this->assertSame($beforeChallenges + 1, OtpChallenge::query()->count());
        $this->assertCount(1, app(FakeOtpDeliveryProvider::class)->sent);
        $context = AccountContext::query()->where('user_id', $user->id)->sole();
        $this->assertNotNull($context->otp_challenge_id);
    }

    #[Test]
    public function contextual_request_code_without_lookup_is_rejected(): void
    {
        $user = $this->eligibleUser();

        $this->loginPost( $this->loginContextPayload($user))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ACCOUNT_CONTEXT_REQUIRED');
    }

    #[Test]
    public function expired_context_rejects_contextual_request_code(): void
    {
        $user = $this->eligibleUser();
        $payload = $this->lookupPayload(['phone' => '+52'.$user->getRawOriginal('phone')]);
        $this->atixPost($payload, $this->lookupHeaders($payload))->assertStatus(202);

        Carbon::setTestNow(now()->addMinutes(11));

        $this->loginPost( $this->loginContextPayload($user))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ACCOUNT_CONTEXT_REQUIRED');
    }

    #[Test]
    public function conversation_isolation_requires_fresh_lookup(): void
    {
        $user = $this->eligibleUser();
        $phone = '+52'.$user->getRawOriginal('phone');
        $first = $this->lookupPayload(['phone' => $phone, 'conversation_id' => 'conv-a-'.$this->runId]);
        $this->atixPost($first, $this->lookupHeaders($first))->assertStatus(202);

        $login = $this->loginContextPayload($user);
        $login['conversation_id'] = 'conv-b-'.$this->runId;
        $this->loginPost( $login)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ACCOUNT_CONTEXT_REQUIRED');
    }

    #[Test]
    public function channel_isolation_requires_matching_binding(): void
    {
        config()->set('atix.sandbox.allowed_channels', ['whatsapp', 'telegram']);
        $user = $this->eligibleUser();
        $payload = $this->lookupPayload([
            'phone' => '+52'.$user->getRawOriginal('phone'),
            'channel' => 'telegram',
            'channel_event_id' => 'event-tg-'.$this->runId,
        ]);
        $this->atixPost($payload, $this->lookupHeaders($payload))->assertStatus(202);

        $login = $this->loginContextPayload($user);
        $login['channel'] = 'whatsapp';
        $this->loginPost( $login)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ACCOUNT_CONTEXT_REQUIRED');
    }

    #[Test]
    public function responses_never_expose_account_context_token_or_bearer(): void
    {
        $user = $this->eligibleUser();
        $payload = $this->lookupPayload(['phone' => '+52'.$user->getRawOriginal('phone')]);
        $lookup = $this->atixPost($payload, $this->lookupHeaders($payload))->assertStatus(202);

        $this->assertStringNotContainsString('account_context_token', json_encode($lookup->json()));
        $this->assertStringNotContainsString('"token"', json_encode($lookup->json('data')));

        $otp = $this->loginPost($this->loginContextPayload($user))
            ->assertStatus(202);

        $this->assertStringNotContainsString('account_context_token', json_encode($otp->json()));
    }

    #[Test]
    public function correlation_id_is_echoed_on_lookup(): void
    {
        $payload = $this->lookupPayload();
        $this->atixPost($payload, $this->lookupHeaders($payload))
            ->assertStatus(202)
            ->assertHeader('X-Correlation-ID', 'corr-'.$this->runId);
    }

    private function eligibleUser(): User
    {
        $phone = '551'.str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT);

        return User::factory()->withRegularCustomer()->create([
            'phone' => $phone,
            'phone_country' => 'MX',
            'phone_verified_at' => now(),
            'name' => 'Ada Lovelace',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function lookupPayload(array $overrides = []): array
    {
        return array_merge([
            'phone' => '+525588'.random_int(100000, 999999),
            'conversation_id' => 'conv-'.$this->runId,
            'channel_event_id' => 'event-'.$this->runId,
            'channel' => 'whatsapp',
            'audience' => 'leo',
            'personalization' => ['allowed' => true],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function loginContextPayload(User $user): array
    {
        return [
            'phone' => $user->getRawOriginal('phone'),
            'conversation_id' => 'conv-'.$this->runId,
            'channel_event_id' => 'event-'.$this->runId,
            'channel' => 'whatsapp',
            'audience' => 'leo',
            'personalization' => ['allowed' => true],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function lookupHeaders(array $payload, ?string $nonce = null, ?string $idempotency = null): array
    {
        return AtixSandboxRequestBuilder::headers(
            $payload,
            nonce: $nonce ?? 'nonce-'.$this->runId,
            idempotency: $idempotency ?? 'idem-'.$this->runId,
            correlationId: 'corr-'.$this->runId,
        );
    }

    private function atixPost(array $payload, array $headers)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->withHeaders($headers)
            ->postJson('/api/v1/integrations/atix/phone-lookups', $payload);
    }

    private function loginPost(array $payload)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login/request-code', $payload);
    }
}
