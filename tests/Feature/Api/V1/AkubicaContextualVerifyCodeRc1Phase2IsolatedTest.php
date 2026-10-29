<?php

namespace Tests\Feature\Api\V1;

use App\Models\AccountContext;
use App\Models\Api\V1\ApiV1AuditEvent;
use App\Services\Api\V1\Audit\AuditEventDefinitions;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\Otp\Delivery\FakeOtpDeliveryProvider;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Atix\AtixSandboxRequestBuilder;
use Tests\TestCase;

final class AkubicaContextualVerifyCodeRc1Phase2IsolatedTest extends TestCase
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
        disableAllAkubicaOtpFeatures();
        enableLoginOtpWithFakeDelivery();
        config()->set('atix.sandbox.keys', ['sandbox-test' => 'sandbox-secret']);
        config()->set('api_v1.audit.enabled', true);
        config()->set('otp.p0a.account_context.ttl_minutes', 10);
        $this->app->instance(\App\Contracts\Otp\OtpCodeGenerator::class, new \Tests\Support\Otp\FakeOtpCodeGenerator('123456'));
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
    public function contextual_verify_issues_real_bearer_with_assurance_level(): void
    {
        $user = $this->eligibleUser();
        $challengeId = $this->startContextualOtp($user);

        $response = $this->verifyPost([
            'challenge_id' => $challengeId,
            'code' => '123456',
        ])->assertOk();

        $response->assertJsonPath('data.otp_verified', true)
            ->assertJsonPath('data.assurance_level', 'otp_verified')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['token', 'expires_in', 'expires_at']])
            ->assertJsonMissingPath('data.account_context_token');

        $this->assertStringNotContainsString('account_context_token', json_encode($response->json()));
    }

    #[Test]
    public function bearer_from_contextual_verify_allows_membership_and_own_medical_cart(): void
    {
        $user = $this->eligibleUser();
        $challengeId = $this->startContextualOtp($user);
        $token = $this->verifyPost([
            'challenge_id' => $challengeId,
            'code' => '123456',
        ])->assertOk()->json('data.token');

        $this->getJson('/api/v1/memberships/status', authHeaders($token))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson('/api/v1/cart?service_line=medical_services', authHeaders($token))
            ->assertOk()
            ->assertJsonPath('data.service_line', 'medical_services');
    }

    #[Test]
    public function bearer_does_not_grant_access_to_another_customers_membership_context(): void
    {
        $owner = $this->eligibleUser();
        $other = User::factory()->withRegularCustomer()->create([
            'phone' => '552'.str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT),
            'phone_country' => 'MX',
            'phone_verified_at' => now(),
        ]);

        $challengeId = $this->startContextualOtp($owner);
        $ownerToken = $this->verifyPost([
            'challenge_id' => $challengeId,
            'code' => '123456',
        ])->assertOk()->json('data.token');

        $this->getJson('/api/v1/memberships/status', authHeaders($ownerToken))
            ->assertOk()
            ->assertJsonPath('data.status', 'none');

        $otherToken = $other->createToken('other')->plainTextToken;
        $this->getJson('/api/v1/memberships/status', authHeaders($otherToken))
            ->assertOk();

        $this->assertNotSame(
            $owner->customer->id,
            User::query()->find($other->id)?->customer?->id,
        );
    }

    #[Test]
    public function invalid_otp_does_not_issue_bearer(): void
    {
        $user = $this->eligibleUser();
        $challengeId = $this->startContextualOtp($user);

        $this->verifyPost([
            'challenge_id' => $challengeId,
            'code' => '000000',
        ])->assertStatus(422);

        $this->getJson('/api/v1/memberships/status')->assertUnauthorized();
    }

    #[Test]
    public function expired_otp_does_not_issue_bearer(): void
    {
        $user = $this->eligibleUser();
        $challengeId = $this->startContextualOtp($user);
        Carbon::setTestNow(now()->addMinutes(6));

        $this->verifyPost([
            'challenge_id' => $challengeId,
            'code' => '123456',
        ])->assertStatus(422);
    }

    #[Test]
    public function otp_replay_is_rejected(): void
    {
        $user = $this->eligibleUser();
        $challengeId = $this->startContextualOtp($user);

        $this->verifyPost([
            'challenge_id' => $challengeId,
            'code' => '123456',
        ])->assertOk();

        $this->verifyPost([
            'challenge_id' => $challengeId,
            'code' => '123456',
        ])->assertStatus(422);
    }

    #[Test]
    public function mismatched_conversation_context_is_rejected_at_verify(): void
    {
        $user = $this->eligibleUser();
        $challengeId = $this->startContextualOtp($user);
        $context = AccountContext::query()->where('user_id', $user->id)->sole();
        $context->forceFill([
            'conversation_hash' => hash_hmac('sha256', 'other-conversation', (string) config('app.key')),
        ])->save();

        $this->verifyPost([
            'challenge_id' => $challengeId,
            'code' => '123456',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'ACCOUNT_CONTEXT_EXPIRED');
    }

    #[Test]
    public function mismatched_audience_context_is_rejected_at_verify(): void
    {
        $user = $this->eligibleUser();
        $challengeId = $this->startContextualOtp($user);
        $context = AccountContext::query()->where('user_id', $user->id)->sole();
        $context->forceFill(['audience' => 'other-audience'])->save();

        $this->verifyPost([
            'challenge_id' => $challengeId,
            'code' => '123456',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'ACCOUNT_CONTEXT_EXPIRED');
    }

    #[Test]
    public function normal_login_without_context_still_issues_bearer(): void
    {
        $user = User::factory()->withRegularCustomer()->create([
            'phone' => '553'.str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT),
            'phone_country' => 'MX',
            'phone_verified_at' => now(),
        ]);

        $challengeId = $this->requestCodePost([
            'phone' => $user->getRawOriginal('phone'),
        ])->assertStatus(202)->json('data.challenge_id');

        $this->verifyPost([
            'challenge_id' => $challengeId,
            'code' => '123456',
        ])->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonMissingPath('data.otp_verified')
            ->assertJsonMissingPath('data.assurance_level');
    }

    #[Test]
    public function full_flow_keeps_a_single_account_context(): void
    {
        $user = $this->eligibleUser();
        $challengeId = $this->startContextualOtp($user);

        $this->verifyPost([
            'challenge_id' => $challengeId,
            'code' => '123456',
        ])->assertOk();

        $this->assertSame(1, AccountContext::query()->where('user_id', $user->id)->count());
        $this->assertNotNull(AccountContext::query()->where('user_id', $user->id)->sole()->verified_at);
    }

    #[Test]
    public function audit_records_do_not_contain_account_context_token(): void
    {
        $user = $this->eligibleUser();
        $challengeId = $this->startContextualOtp($user);
        $this->verifyPost([
            'challenge_id' => $challengeId,
            'code' => '123456',
        ])->assertOk();

        $verified = ApiV1AuditEvent::query()
            ->where('event_name', AuditEventDefinitions::EVENT_LOGIN_VERIFIED)
            ->latest('id')
            ->first();

        $this->assertNotNull($verified);
        $serialized = json_encode($verified->toArray());
        $this->assertStringNotContainsString('account_context_token', $serialized);
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

    private function startContextualOtp(User $user): string
    {
        $conversationId = 'conv-'.$this->runId;
        $eventId = 'event-'.$this->runId;
        $atixPayload = [
            'phone' => '+52'.$user->getRawOriginal('phone'),
            'conversation_id' => $conversationId,
            'channel_event_id' => $eventId,
            'channel' => 'whatsapp',
            'audience' => 'leo',
            'personalization' => ['allowed' => true],
        ];

        $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->withHeaders(AtixSandboxRequestBuilder::headers(
                $atixPayload,
                nonce: 'nonce-atix-'.$this->runId,
                idempotency: 'idem-atix-'.$this->runId,
                correlationId: 'corr-atix-'.$this->runId,
            ))->postJson('/api/v1/integrations/atix/phone-lookups', $atixPayload)->assertStatus(202);

        return $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login/request-code', [
            'phone' => $user->getRawOriginal('phone'),
            'conversation_id' => $conversationId,
            'channel_event_id' => $eventId,
            'channel' => 'whatsapp',
            'audience' => 'leo',
            'personalization' => ['allowed' => true],
            ])->assertStatus(202)->json('data.challenge_id');
    }

    private function verifyPost(array $payload)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login/verify-code', $payload);
    }

    private function requestCodePost(array $payload)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login/request-code', $payload);
    }
}
