<?php

namespace Famedic\Stabilization;

use App\Actions\Api\V1\Auth\IssueAkubicaTokenAction;
use App\Contracts\Api\V1\Auth\BackendCredentialSource;
use App\Models\ApiConsumerTokenBinding;
use App\Models\User;
use App\Services\Api\V1\Auth\OtpConsumerEnrollment;
use App\Services\Api\V1\Auth\OtpEnrollmentOperator;
use App\Services\Api\V1\Auth\PendingOtpConsumerEnrollment;
use App\Services\Api\V1\Auth\UnavailableOtpEnrollmentOperator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Api\V1\Identity\LocalMachineCredentialSource;
use Tests\Support\Api\V1\Identity\OtpEnrollmentFixture;
use Tests\TestCase;

final class OtpBindingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Queue::fake();
        Notification::fake();
        Http::fake();
        $this->app->bind(BackendCredentialSource::class, LocalMachineCredentialSource::class);
    }

    private function user(): User
    {
        return User::factory()->withRegularCustomer()->create();
    }

    private function client(User $user): void
    {
        OtpEnrollmentFixture::existing($user);
        $req = Request::create('/api/v1/auth/login/verify-code', 'POST', ['consumer_id' => 'forged-native', 'capabilities' => ['*']]);
        foreach (OtpEnrollmentFixture::headers() as $h => $v) {
            $req->headers->set($h, $v);
        }$this->app->instance('request', $req);
    }

    private function denied(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected issuance denial');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function default_issuer_does_not_enable_enrollment(): void
    {
        $u = $this->user();
        $this->assertInstanceOf(PendingOtpConsumerEnrollment::class, app(OtpConsumerEnrollment::class));
        $r = app(IssueAkubicaTokenAction::class)($u);
        $pat = PersonalAccessToken::findToken($r['token']);
        $this->assertSame(0, ApiConsumerTokenBinding::where('personal_access_token_id', $pat->id)->count());
    }

    #[Test]
    public function approved_internal_enrollment_uses_verified_machine_and_server_grants(): void
    {
        $u = $this->user();
        $this->client($u);
        $r = app(IssueAkubicaTokenAction::class)($u);
        $pat = PersonalAccessToken::findToken($r['token']);
        $binding = ApiConsumerTokenBinding::where('personal_access_token_id', $pat->id)->sole();
        $this->assertSame(['memberships:read', 'cart:read'], $pat->abilities);
        $this->assertSame((int) $u->id, (int) $pat->tokenable_id);
        $this->assertLessThanOrEqual(now()->addMinutes(40)->timestamp, $pat->expires_at->timestamp);
        $this->assertNotSame('forged-native', (string) $binding->consumer_id);
    }

    #[Test]
    public function missing_backend_proof_has_no_legacy_fallback_when_enrollment_enabled(): void
    {
        $u = $this->user();
        $this->client($u);
        foreach (OtpEnrollmentFixture::headers() as $h => $v) {
            request()->headers->remove($h);
        }$before = PersonalAccessToken::count();
        $this->denied(fn () => app(IssueAkubicaTokenAction::class)($u));
        $this->assertSame($before, PersonalAccessToken::count());
    }

    #[Test]
    public function missing_authority_and_foreign_user_are_denied_without_issuing_pat(): void
    {
        $u = $this->user();
        $this->client($u);
        $other = $this->user();
        $before = PersonalAccessToken::count();
        $this->denied(fn () => app(IssueAkubicaTokenAction::class)($other));
        $this->app->instance(OtpEnrollmentOperator::class, new UnavailableOtpEnrollmentOperator);
        $this->denied(fn () => app(IssueAkubicaTokenAction::class)($u));
        $this->assertSame($before, PersonalAccessToken::count());
    }

    #[Test]
    public function binding_failure_rolls_back_fresh_pat(): void
    {
        $u = $this->user();
        $this->client($u);
        $before = PersonalAccessToken::count();
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER synthetic_binding_failure BEFORE INSERT ON api_consumer_token_bindings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic failure'");
        } else {
            DB::unprepared("CREATE TRIGGER synthetic_binding_failure BEFORE INSERT ON api_consumer_token_bindings BEGIN SELECT RAISE(ABORT,'synthetic failure'); END");
        }try {
            try {
                app(IssueAkubicaTokenAction::class)($u);
                $this->fail('Expected storage failure');
            } catch (\Illuminate\Database\QueryException) {
                $this->assertSame($before, PersonalAccessToken::count());
            }
        } finally {
            DB::unprepared('DROP TRIGGER synthetic_binding_failure');
        }
    }

    #[Test]
    public function operator_permission_cannot_be_replaced_by_user_role_or_headers(): void
    {
        $u = $this->user();
        $this->client($u);
        $before = PersonalAccessToken::count();
        $this->denied(fn () => app(IssueAkubicaTokenAction::class)($u));
        $this->assertSame($before, PersonalAccessToken::count());
    }

    #[Test]
    public function malformed_sdk_envelope_is_not_accepted_as_success(): void
    {
        foreach ([new \Vonage\SMS\Collection([]), new \Vonage\SMS\Collection(['message-count' => 1, 'messages' => [['status' => 'not-a-number']]])] as $response) {
            $result = \App\Services\Otp\Delivery\VonageSmsSendResponseParser::parse($response);
            $this->assertFalse($result->accepted);
            $this->assertFalse($result->interpretable);
        }
    }

    #[Test]
    public function valid_sdk_response_retains_its_accepted_status_and_identifier(): void
    {
        require_once dirname(__DIR__, 3).'/tests/Support/Otp/FakeVonageSmsSendGateway.php';
        $response = \Tests\Support\Otp\vonageSmsCollection(0, messageId: 'synthetic-sdk-message');
        $result = \App\Services\Otp\Delivery\VonageSmsSendResponseParser::parse($response);
        $this->assertTrue($result->accepted);
        $this->assertSame('synthetic-sdk-message',$result->messageId);
    }
}
