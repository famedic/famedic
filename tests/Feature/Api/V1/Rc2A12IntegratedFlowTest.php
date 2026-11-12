<?php

namespace Tests\Feature\Api\V1;

require_once __DIR__.'/../../../Support/Otp/akubica_otp_testing_helpers.php';
require_once __DIR__.'/../../../Support/Api/V1/rc2_feature_test_helpers.php';

use App\Models\User;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Api\V1\Identity\OtpEnrollmentFixture;
use Tests\Support\Atix\AtixSandboxRequestBuilder;
use Tests\Support\Database\Rc2IsolatedDatabase;
use Tests\TestCase;

final class Rc2A12IntegratedFlowTest extends TestCase
{
    use Rc2IsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetRc2IsolatedState();
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00'));
        config()->set('akubica_connectors.transport', ['enabled' => true, 'certified' => true]);
        config()->set('akubica_connectors.otp_enrollment', ['enabled' => true, 'certified' => true]);
        config()->set('atix.sandbox.keys', ['sandbox-test' => 'sandbox-secret']);
        enableLoginOtpWithFakeDelivery();
        $this->app->instance(
            \App\Contracts\Otp\OtpCodeGenerator::class,
            new \Tests\Support\Otp\FakeOtpCodeGenerator('123456')
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        disableAllAkubicaOtpFeatures();
        parent::tearDown();
    }

    #[Test]
    public function leo_can_read_revoke_own_bearer_and_is_rejected_afterward(): void
    {
        $this->assertNotContains(
            \App\Http\Middleware\Api\V1\AuthorizeNativeConsumerOperation::class,
            app('router')->getRoutes()->getByName('api.v1.auth.token.revoke')->gatherMiddleware()
        );

        $user = User::factory()->withRegularCustomer()->create([
            'phone' => '5500000001',
            'phone_country' => 'MX',
            'phone_verified_at' => now(),
        ]);

        OtpEnrollmentFixture::existing($user, 'leo');
        $backend = OtpEnrollmentFixture::headers();
        $payload = [
            'phone' => '+525500000001',
            'conversation_id' => 'rc2-integrated-conversation',
            'channel_event_id' => 'rc2-integrated-event',
            'channel' => 'whatsapp',
            'audience' => 'leo',
            'personalization' => ['allowed' => false],
        ];

        $this->withHeaders(AtixSandboxRequestBuilder::headers(
            $payload,
            nonce: 'rc2-integrated-nonce',
            idempotency: 'rc2-integrated-idempotency',
            correlationId: 'rc2-integrated-correlation',
        ))->postJson('/api/v1/integrations/atix/phone-lookups', $payload)
            ->assertStatus(202);

        $challenge = $this->withHeaders($backend)
            ->postJson('/api/v1/auth/login/request-code', $payload)
            ->assertStatus(202)
            ->json('data.challenge_id');

        $token = $this->withHeaders($backend)
            ->postJson('/api/v1/auth/login/verify-code', [
                'challenge_id' => $challenge,
                'code' => '123456',
            ])
            ->assertOk()
            ->assertJsonPath('data.otp_verified', true)
            ->json('data.token');

        OtpEnrollmentFixture::issued($token);
        $this->assertNotNull(\Laravel\Sanctum\PersonalAccessToken::findToken($token));
        $headers = ['Authorization' => 'Bearer '.$token] + $backend;

        $this->getJson('/api/v1/memberships/status', $headers)->assertOk();
        $this->assertNotNull(\Laravel\Sanctum\PersonalAccessToken::findToken($token));
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        config(['sanctum.guard' => []]);
        $this->deleteJson('/api/v1/auth/token', [], $headers)
            ->assertOk()
            ->assertJsonPath('data.revoked', true);
        $this->app['auth']->forgetGuards();
        config(['sanctum.guard' => []]);
        $this->getJson('/api/v1/memberships/status', $headers)->assertUnauthorized();
        $this->deleteJson('/api/v1/auth/token', [], $headers)->assertUnauthorized();
    }

    #[Test]
    public function token_revoke_rejects_missing_and_invalid_bearers(): void
    {
        $this->deleteJson('/api/v1/auth/token')->assertUnauthorized();
        $this->deleteJson('/api/v1/auth/token', [], [
            'Authorization' => 'Bearer invalid-token',
        ])->assertUnauthorized();
    }
}
