<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MedicalSubscriptionType;
use App\Models\AccountContext;
use App\Models\MedicalAttentionSubscription;
use App\Models\OtpChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Otp\FakeOtpCodeGenerator;
use Tests\TestCase;

/**
 * M1 isolated suite. The testing schema is prepared outside PHPUnit.
 * This class deliberately does not refresh, truncate, migrate, or transact.
 */
final class AkubicaMembershipsM1IsolatedTest extends TestCase
{
    private string $runId;
    private string $clientIp;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00'));
        $this->runId = (string) random_int(10000000, 99999999);
        $this->clientIp = '10.'.random_int(1, 254).'.'.random_int(1, 254).'.'.random_int(1, 254);
        enableLoginOtpWithFakeDelivery();
        config()->set('atix.sandbox.keys', ['sandbox-test' => 'sandbox-secret']);
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
    public function it_returns_the_canonical_basic_plan(): void
    {
        $this->getJson('/api/v1/memberships/plans')
            ->assertOk()
            ->assertJsonPath('data.plans.0.code', 'basic_annual')
            ->assertJsonPath('data.plans.0.price_cents', 30000)
            ->assertJsonPath('data.plans.0.currency', 'MXN');
    }

    #[Test]
    public function it_classifies_none_individual_sponsored_and_expired_memberships(): void
    {
        [$user, $token] = akubicaCustomerToken();

        $this->getJson('/api/v1/memberships/status', authHeaders($token))
            ->assertOk()->assertJsonPath('data.status', 'none')
            ->assertJsonPath('data.purchase_eligible', true);

        $this->createSubscription($user->customer, MedicalSubscriptionType::REGULAR);
        $this->getJson('/api/v1/memberships/status', authHeaders($token))
            ->assertOk()->assertJsonPath('data.source', 'individual')
            ->assertJsonPath('data.purchase_eligible', false);

        $sponsored = User::factory()->withRegularCustomer()->create(['email' => 'm1-sponsored-'.uniqid().'@example.test']);
        $sponsoredToken = $sponsored->createToken('m1-isolated')->plainTextToken;
        $this->createSubscription($sponsored->customer, MedicalSubscriptionType::INSTITUTIONAL->value);
        $this->actingAs($sponsored, 'sanctum')->getJson('/api/v1/memberships/status')
            ->assertOk()->assertJsonPath('data.source', 'sponsored')
            ->assertJsonPath('data.purchase_eligible', false);

        $expired = akubicaCustomerToken()[0];
        $expiredToken = $expired->createToken('m1-isolated')->plainTextToken;
        $this->createSubscription($expired->customer, MedicalSubscriptionType::REGULAR, [
            'start_date' => now()->subYear()->subDay()->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
        ]);
        $this->actingAs($expired, 'sanctum')->getJson('/api/v1/memberships/status')
            ->assertOk()->assertJsonPath('data.status', 'expired')
            ->assertJsonPath('data.purchase_eligible', true);
    }

    #[Test]
    public function it_enforces_authenticated_customer_ownership(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('m1-isolated')->plainTextToken;

        $this->getJson('/api/v1/memberships/status', authHeaders($token))
            ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
    }

    #[Test]
    public function it_keeps_contextual_otp_separate_from_bearer_authentication(): void
    {
        $this->getJson('/api/v1/memberships/status')
            ->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer opaque-pre-otp-context')
            ->getJson('/api/v1/memberships/status')
            ->assertUnauthorized();
    }

    #[Test]
    public function it_recognizes_a_contact_pre_otp_and_elevates_the_same_context_after_otp(): void
    {
        $user = User::factory()->withRegularCustomer()->create([
            'phone' => $this->phone('80'), 'phone_country' => 'MX',
            'phone_verified_at' => now(), 'name' => 'Ada Lovelace',
        ]);
        $payload = [
            'phone' => $this->phone('80'), 'conversation_id' => 'm1-otp-conversation-'.$this->runId,
            'channel_event_id' => 'm1-otp-event-'.$this->runId, 'channel' => 'whatsapp',
            'audience' => 'leo',
            'personalization' => ['allowed' => true],
        ];

        $this->atixLookup($payload);

        $start = $this->otpPost('/api/v1/auth/login/request-code', $payload)
            ->assertStatus(202)
            ->assertJsonPath('data.personalization.first_name', 'Ada')
            ->assertJsonMissingPath('data.account_context_token');
        $challengeId = $start->json('data.challenge_id');
        $context = AccountContext::query()->where('user_id', $user->id)->sole();
        $this->assertNull($context->verified_at);

        $this->otpPost('/api/v1/auth/login/verify-code', [
            'challenge_id' => $challengeId, 'code' => '123456',
        ])->assertOk()
            ->assertJsonPath('data.otp_verified', true)
            ->assertJsonPath('data.assurance_level', 'otp_verified')
            ->assertJsonPath('data.personalization.first_name', 'Ada')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['token', 'expires_in', 'expires_at', 'user']])
            ->assertJsonMissingPath('data.account_context_token');

        $this->assertNotNull($context->fresh()->verified_at);
        $this->assertSame(1, OtpChallenge::query()->where('public_id', $challengeId)->count());
    }

    #[Test]
    public function it_blocks_private_membership_data_before_otp_and_does_not_enumerate(): void
    {
        User::factory()->withRegularCustomer()->create([
            'phone' => $this->phone('81'), 'phone_country' => 'MX',
            'phone_verified_at' => now(), 'name' => 'Ada Lovelace',
        ]);

        $unknown = $this->otpPost('/api/v1/auth/login/request-code', [
            'phone' => $this->phone('90'), 'conversation_id' => 'm1-private-unknown-'.$this->runId,
            'channel_event_id' => 'm1-private-event-'.$this->runId, 'channel' => 'whatsapp',
            'audience' => 'leo',
            'personalization' => ['allowed' => true],
        ])->assertStatus(202)->json('data');
        $knownPayload = [
            'phone' => $this->phone('81'), 'conversation_id' => 'm1-private-known-'.$this->runId,
            'channel_event_id' => 'm1-private-event-known-'.$this->runId, 'channel' => 'whatsapp',
            'audience' => 'leo',
            'personalization' => ['allowed' => true],
        ];
        $this->atixLookup($knownPayload);
        $known = $this->otpPost('/api/v1/auth/login/request-code', $knownPayload)
            ->assertStatus(202)->json('data');

        $this->assertSame(array_keys($unknown), array_keys($known));
        $this->getJson('/api/v1/memberships/status')->assertUnauthorized();
    }

    private function createSubscription($customer, MedicalSubscriptionType|string $type, array $dates = []): void
    {
        MedicalAttentionSubscription::query()->create(array_merge([
            'customer_id' => $customer->id,
            'type' => $type,
            'price_cents' => $type === MedicalSubscriptionType::REGULAR ? 30000 : 0,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
        ], $dates));
    }

    private function phone(string $suffix): string
    {
        return '5'.substr($this->runId, 0, 7).$suffix;
    }

    private function otpPost(string $uri, array $payload)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])->postJson($uri, $payload);
    }

    private function atixLookup(array $loginPayload): void
    {
        $atixPayload = [
            'phone' => '+52'.$loginPayload['phone'],
            'conversation_id' => $loginPayload['conversation_id'],
            'channel_event_id' => $loginPayload['channel_event_id'],
            'channel' => $loginPayload['channel'],
            'audience' => $loginPayload['audience'] ?? 'leo',
            'personalization' => $loginPayload['personalization'] ?? ['allowed' => false],
        ];
        $body = json_encode($atixPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $nonce = 'm1-atix-'.$this->runId;
        $canonical = implode("\n", ['POST', '/api/v1/integrations/atix/phone-lookups', (string) now()->timestamp, $nonce, hash('sha256', (string) $body)]);
        $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->withHeaders([
                'X-Integration-Key-Id' => 'sandbox-test',
                'X-Timestamp' => (string) now()->timestamp,
                'X-Nonce' => $nonce,
                'X-Signature' => hash_hmac('sha256', $canonical, 'sandbox-secret'),
                'X-Correlation-ID' => 'corr-m1-'.$this->runId,
                'Idempotency-Key' => 'idem-m1-'.$this->runId,
            ])
            ->postJson('/api/v1/integrations/atix/phone-lookups', $atixPayload)
            ->assertStatus(202);
    }
}
