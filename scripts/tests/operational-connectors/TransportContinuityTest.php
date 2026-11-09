<?php

namespace Famedic\OperationalConnectors;

use App\Contracts\Api\V1\Auth\BackendClientAuthenticator;
use App\Contracts\Api\V1\Auth\BackendCredentialSource;
use App\Models\ApiConsumerTokenBinding;
use App\Models\Coupon;
use App\Models\CouponUser;
use App\Models\User;
use App\Services\Api\V1\Auth\ConsumerLifecycle;
use App\Services\Api\V1\Auth\OtpEnrollmentOperator;
use App\Services\Api\V1\Auth\RegistryOtpEnrollmentOperator;
use App\Services\Api\V1\Auth\TlsMachinePatCredentialSource;
use App\Services\Api\V1\Benefits\BenefitApprovals;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture as Fixture;
use Tests\TestCase;

final class ConnectorApprovals implements BenefitApprovals
{
    public function api(): bool
    {
        return true;
    }

    public function consent(): bool
    {
        return true;
    }

    public function financial(): bool
    {
        return false;
    }

    public function textVersion(): ?string
    {
        return 'synthetic-connectors-v1';
    }
}
final class TransportContinuityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Queue::fake();
        Notification::fake();
        Http::fake();
        config()->set('famedic.medical_attention_subscription_price_cents', 1000);
    }

    private function enabled(): void
    {
        config()->set('akubica_connectors.transport', ['enabled' => true, 'certified' => true]);
        $this->app->bind(BackendCredentialSource::class, TlsMachinePatCredentialSource::class);
    }

    private function actor(): array
    {
        return Fixture::issue(User::factory()->withRegularCustomer()->create(), 'leo', ['memberships:read', 'cart:read', 'checkout:prepare', 'benefits:read', 'benefits:apply', 'benefits:remove']);
    }

    private function headers(array $a): array
    {
        return ['Authorization' => 'Bearer '.$a['token']->plainTextToken, 'X-Consumer-Credential' => $a['machine']->plainTextToken];
    }

    #[Test]
    public function transport_rejects_missing_certification_cleartext_duplicates_and_identity_headers(): void
    {
        $a = $this->actor();
        $source = new TlsMachinePatCredentialSource;
        $r = Request::create('https://local.invalid/api/v1/cart');
        $r->headers->set('X-Consumer-Credential', $a['machine']->plainTextToken);
        $this->assertNull($source->credentialFor($r));
        $this->enabled();
        $this->assertSame($a['machine']->plainTextToken, $source->credentialFor($r));
        $clear = Request::create('http://local.invalid/api/v1/cart');
        $clear->headers->set('X-Consumer-Credential', $a['machine']->plainTextToken);
        $this->assertNull($source->credentialFor($clear));
        foreach ([$a['machine']->plainTextToken."\n", $a['machine']->plainTextToken.',duplicate', 'leo', 'Bearer '.$a['machine']->plainTextToken] as $v) {
            $r->headers->set('X-Consumer-Credential', $v);
            $this->assertNull($source->credentialFor($r));
        }
        $r->headers->set('X-Consumer-Credential', [$a['machine']->plainTextToken, $a['machine']->plainTextToken]);
        $this->assertNull($source->credentialFor($r));
        $r->headers->remove('X-Consumer-Credential');
        $r->headers->set('X-Consumer-Id', 'leo');
        $this->assertNull(app(BackendClientAuthenticator::class)->authenticate($r));
    }

    #[Test]
    public function verified_transport_access_revocation_and_payment_denial_are_http_real(): void
    {
        $a = $this->actor();
        $this->enabled();
        $headers = $this->headers($a);
        $this->getJson('https://local.invalid/api/v1/memberships/status', $headers)->assertOk();
        $this->postJson('https://local.invalid/api/v1/checkout/payment-link', ['brand' => 'olab'], $headers)->assertForbidden();
        app(ConsumerLifecycle::class)->transition($a['issuer'], $a['credential'], false, 'synthetic-revocation-01');
        $this->getJson('https://local.invalid/api/v1/memberships/status', $headers)->assertForbidden();
        $this->assertSame(1, DB::table('api_consumer_lifecycle_events')->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function registry_operator_binds_actual_http_otp_after_verification(): void
    {
        $a = $this->actor();
        $this->enabled();
        $a['user']->update(['phone' => '5500000001', 'phone_country' => 'MX', 'phone_verified_at' => now()]);
        config()->set('akubica_connectors.otp_enrollment', ['enabled' => true, 'certified' => true]);
        app(ConsumerLifecycle::class)->authorizeOtp($a['issuer'], $a['channel'], $a['issuer'], 'synthetic-otp-approval', now()->addMinutes(20));
        $this->assertInstanceOf(RegistryOtpEnrollmentOperator::class, app(OtpEnrollmentOperator::class));
        \enableLoginOtpWithFakeDelivery();
        $this->app->instance(\App\Contracts\Otp\OtpCodeGenerator::class, new \Tests\Support\Otp\FakeOtpCodeGenerator('000000'));
        $headers = ['X-Consumer-Credential' => $a['machine']->plainTextToken];
        $start = $this->postJson('https://local.invalid/api/v1/auth/login/request-code', ['phone' => '+525500000001'], $headers)->assertStatus(202)->json('data');
        $this->assertCount(8, $start);
        $this->assertSame(['allowed' => false], $start['personalization']);
        $decoy = $this->postJson('https://local.invalid/api/v1/auth/login/request-code', ['phone' => '+525500000099'], $headers)->assertStatus(202)->json('data');
        $this->assertSame(array_keys($start), array_keys($decoy));
        $this->assertCount(8, $decoy);
        $this->assertSame(['allowed' => false], $decoy['personalization']);
        $verified = $this->postJson('https://local.invalid/api/v1/auth/login/verify-code', ['challenge_id' => $start['challenge_id'], 'code' => '000000'], $headers)->assertOk()->json('data');
        $pat = \Laravel\Sanctum\PersonalAccessToken::findToken($verified['token']);
        $binding = ApiConsumerTokenBinding::where('personal_access_token_id', $pat->id)->sole();
        $this->assertSame((int) $a['consumer']->id, (int) $binding->consumer_id);
        $this->getJson('https://local.invalid/api/v1/memberships/status', ['Authorization' => 'Bearer '.$verified['token']] + $headers)->assertOk();
    }

    #[Test]
    public function revoked_issuer_authority_never_falls_back_to_unbound_pat(): void
    {
        $a = $this->actor();
        $this->enabled();
        config()->set('akubica_connectors.otp_enrollment', ['enabled' => true, 'certified' => true]);
        $r = Request::create('https://local.invalid/api/v1/auth/login/verify-code', 'POST');
        $r->headers->set('X-Consumer-Credential', $a['machine']->plainTextToken);
        $this->app->instance('request', $r);
        $n = \Laravel\Sanctum\PersonalAccessToken::count();
        try {
            app(\App\Actions\Api\V1\Auth\IssueAkubicaTokenAction::class)($a['user']);
            $this->fail('Expected denial');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertSame($n, \Laravel\Sanctum\PersonalAccessToken::count());
        }
    }

    #[Test]
    public function coupon_and_credit_continue_to_opaque_checkout_and_remove_without_spending(): void
    {
        foreach (['coupon', 'credit'] as $kind) {
            $a = $this->actor();
            $this->enabled();
            $this->app->instance(BenefitApprovals::class, new ConnectorApprovals);
            config()->set('akubica_connectors.continuity', ['enabled' => true, 'certified' => true]);
            app(\App\Services\Api\V1\MedicalServiceCartService::class)->addBasicPlan($a['user']->customer);
            $c = Coupon::factory()->create(['type' => $kind === 'credit' ? \App\Enums\CouponType::Balance : \App\Enums\CouponType::Coupon, 'amount_cents' => 400, 'remaining_cents' => 400]);
            CouponUser::create(['coupon_id' => $c->id, 'user_id' => $a['user']->id]);
            $h = $this->headers($a);
            $base = 'https://local.invalid';
            $s = $this->getJson($base.'/api/v1/cart/benefits?service_line=medical_services', $h)->assertOk()->json('data');
            $this->flushHeaders();
            $this->post('/logout');
            $this->post('/login', ['email' => $a['user']->email, 'password' => 'password'])->assertRedirect();
            $this->post('/confirm-password', ['password' => 'password'])->assertRedirect();
            $intent = ['cart_ref' => $s['cart_ref'], 'cart_version' => $s['cart_version'], 'target_ref' => $s['benefits'][0]['benefit_ref'], 'operation' => 'apply', 'text_version' => 'synthetic-connectors-v1'];
            $this->postJson('/benefits/consent/review', $intent)->assertOk();
            $cnf = $this->postJson('/benefits/consent/confirm', $intent)->assertOk()->json('data.confirmation_ref');
            $body = ['service_line' => 'medical_services', 'cart_ref' => $s['cart_ref'], 'cart_version' => $s['cart_version'], 'benefit_ref' => $s['benefits'][0]['benefit_ref'], 'confirmation_ref' => $cnf];
            $this->flushHeaders();
            $ap = $this->postJson($base.'/api/v1/cart/benefits/applications', $body, $h + ['Idempotency-Key' => 'synthetic-connector-apply-0001'])->assertCreated()->json('data');
            foreach (['/cart', '/checkout/prepare'] as $path) {
                $data = $this->getJson($base.'/api/v1'.$path.'?service_line=medical_services', $h)->assertOk()->assertJsonPath('data.cart.totals.total_cents', 600)->assertJsonPath('data.assistant_can_pay', false)->json('data');
                $this->assertStringNotContainsString('coupon_id', json_encode($data));
                $this->assertStringNotContainsString('cart_id', json_encode($data));
                $this->assertStringNotContainsString('credit_id', json_encode($data));
            }
            $this->getJson($base.'/api/v1/cart/medical-benefit?service_line=medical_services', $h)->assertForbidden();
            try {
                app(\App\Actions\PayPal\CreateMedicalAttentionPayPalOrderAction::class)($a['user']->customer);
                $this->fail('Full-price bypass');
            } catch (\App\Domain\Akubica\Benefits\BenefitFailure $e) {
                $this->assertSame('MEDICAL_BENEFIT_CHECKOUT_REQUIRES_SETTLEMENT', $e->errorCode);
            }
            $next = $this->getJson($base.'/api/v1/cart/benefits?service_line=medical_services', $h)->assertOk()->json('data');
            $this->flushHeaders();
            $receipt = $this->postJson('/benefits/consent/confirm', ['cart_ref' => $next['cart_ref'], 'cart_version' => $next['cart_version'], 'target_ref' => $ap['application_ref'], 'operation' => 'remove', 'text_version' => 'synthetic-connectors-v1'])->assertOk()->json('data.confirmation_ref');
            $this->flushHeaders();
            $this->postJson($base.'/api/v1/cart/benefits/applications/'.$ap['application_ref'].'/remove', ['service_line' => 'medical_services', 'cart_ref' => $next['cart_ref'], 'cart_version' => $next['cart_version'], 'confirmation_ref' => $receipt], $h + ['Idempotency-Key' => 'synthetic-connector-remove-0001'])->assertOk()->assertJsonPath('data.totals.total_cents', 1000);
            $this->assertSame(400, (int) $c->fresh()->remaining_cents);
            Http::assertNothingSent();
        }
    }

    #[Test]
    public function otp_missing_machine_is_rejected_before_delivery_and_cross_consumer_challenge_is_denied(): void
    {
        $a = $this->actor();
        $this->enabled();
        $a['user']->update(['phone' => '5500000001', 'phone_country' => 'MX', 'phone_verified_at' => now()]);
        config()->set('akubica_connectors.otp_enrollment', ['enabled' => true, 'certified' => true]);
        app(ConsumerLifecycle::class)->authorizeOtp($a['issuer'], $a['channel'], $a['issuer'], 'synthetic-cross-context', now()->addMinutes(20));
        \enableLoginOtpWithFakeDelivery();
        $this->app->instance(\App\Contracts\Otp\OtpCodeGenerator::class, new \Tests\Support\Otp\FakeOtpCodeGenerator('000000'));
        $this->postJson('https://local.invalid/api/v1/auth/login/request-code', ['phone' => '+525500000001'], ['X-Consumer-Id' => 'leo'])->assertUnauthorized();
        $this->assertSame(0, \App\Models\OtpChallenge::count());
        $h = ['X-Consumer-Credential' => $a['machine']->plainTextToken];
        $start = $this->postJson('https://local.invalid/api/v1/auth/login/request-code', ['phone' => '+525500000001'], $h)->assertStatus(202)->json('data');
        $b = Fixture::issue($a['user'], 'leo', ['memberships:read']);
        $bh = ['X-Consumer-Credential' => $b['machine']->plainTextToken];
        $this->postJson('https://local.invalid/api/v1/auth/login/verify-code', ['challenge_id' => $start['challenge_id'], 'code' => '000000'], $bh)->assertForbidden()->assertJsonPath('error.code', 'OTP_BACKEND_CONTEXT_INVALID');
        $this->postJson('https://local.invalid/api/v1/auth/login/resend-code', ['challenge_id' => $start['challenge_id']], $bh)->assertForbidden();
        \Illuminate\Support\Facades\Gate::define('manageApiConsumerRegistry', fn (User $u) => (int) $u->id === (int) $a['issuer']->id);
        $this->postJson('https://local.invalid/api/v1/auth/login/verify-code', ['challenge_id' => $start['challenge_id'], 'code' => '000000'], $h)->assertOk();
    }

    #[Test]
    public function native_renewal_requires_verified_machine_and_registry_operator(): void
    {
        $a = Fixture::issue(User::factory()->withRegularCustomer()->create(), 'native', ['memberships:read']);
        $this->enabled();
        $r = Request::create('https://local.invalid/internal/renew', 'POST');
        $r->headers->set('X-Consumer-Credential', $a['machine']->plainTextToken);
        $new = app(\App\Services\Api\V1\Auth\NativeConsumerMigration::class)->renew($a['issuer'], $a['delegation'], $r, now()->addMinutes(15));
        $this->assertNotSame($a['token']->accessToken->id, $new['token']->accessToken->id);
        $this->getJson('https://local.invalid/api/v1/memberships/status', ['Authorization' => 'Bearer '.$new['token']->plainTextToken, 'X-Consumer-Credential' => $a['machine']->plainTextToken])->assertOk();
        $r->headers->remove('X-Consumer-Credential');
        $before = \Laravel\Sanctum\PersonalAccessToken::count();
        try {
            app(\App\Services\Api\V1\Auth\NativeConsumerMigration::class)->renew($a['issuer'], $a['delegation'], $r, now()->addMinutes(15));
            $this->fail('Missing machine renewed');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertSame($before, \Laravel\Sanctum\PersonalAccessToken::count());
        }
    }

    #[Test]
    public function certified_consent_renders_immutable_text_and_records_its_digest(): void
    {
        $a = $this->actor();
        $this->enabled();
        config()->set('akubica_connectors.contract.accepted', true);
        config()->set('akubica_connectors.benefits_api', ['enabled' => true, 'certified' => true]);
        config()->set('akubica_connectors.consent', ['enabled' => true, 'certified' => true, 'text_version' => 'synthetic-text-registry-v1']);
        \Illuminate\Support\Facades\Gate::define('manageBenefitConsentText', fn (User $u) => (int) $u->id === (int) $a['issuer']->id);
        $registry = app(\App\Services\Api\V1\Benefits\ConsentTextRegistry::class);
        $text = 'SYNTHETIC CONSENT: confirm this benefit and these totals; no payment.';
        $registry->register($a['issuer'], 'synthetic-text-registry-v1', $text, 'synthetic-text-approval');
        $this->assertFalse(app(BenefitApprovals::class)->consent());
        $registry->activate($a['issuer'], 'synthetic-text-registry-v1', true);
        $this->assertTrue(app(BenefitApprovals::class)->consent());
        try {
            $registry->register($a['issuer'], 'synthetic-text-registry-v1', 'Changed text', 'synthetic-text-approval');
            $this->fail('Mutable text version');
        } catch (\DomainException) {
            $this->assertSame($text, $registry->active('synthetic-text-registry-v1')->text);
        }
        app(\App\Services\Api\V1\MedicalServiceCartService::class)->addBasicPlan($a['user']->customer);
        $coupon = Coupon::factory()->create(['amount_cents' => 400, 'remaining_cents' => 400]);
        CouponUser::create(['coupon_id' => $coupon->id, 'user_id' => $a['user']->id]);
        $snapshot = $this->getJson('https://local.invalid/api/v1/cart/benefits?service_line=medical_services', $this->headers($a))->assertOk()->json('data');
        $this->flushHeaders();
        $this->post('/logout');
        $this->post('/login', ['email' => $a['user']->email, 'password' => 'password'])->assertRedirect();
        $this->post('/confirm-password', ['password' => 'password'])->assertRedirect();
        $intent = ['cart_ref' => $snapshot['cart_ref'], 'cart_version' => $snapshot['cart_version'], 'target_ref' => $snapshot['benefits'][0]['benefit_ref'], 'operation' => 'apply', 'text_version' => 'synthetic-text-registry-v1'];
        $this->post('/benefits/consent/review', $intent)->assertOk()->assertSee($text);
        $this->postJson('/benefits/consent/confirm', $intent)->assertOk();
        $row = DB::table('opaque_benefit_states')->where('bucket', 'confirmations')->sole();
        $receipt = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($row->ciphertext), true);
        $this->assertSame(hash('sha256', $text), $receipt['text_digest']);
        $registry->activate($a['issuer'], 'synthetic-text-registry-v1', false);
        $this->assertFalse(app(BenefitApprovals::class)->api());
    }

    #[Test]
    public function seven_new_operations_and_continuity_emit_real_typed_http_fixtures(): void
    {
        $routes = [];
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') && ! str_starts_with($route->uri(), 'benefits/consent')) {
                continue;
            }
            $routes[] = ['uri' => $route->uri(), 'methods' => $route->methods(), 'name' => $route->getName(), 'middleware' => app('router')->gatherRouteMiddleware($route), 'declared_middleware' => $route->gatherMiddleware(), 'excluded_middleware' => $route->excludedMiddleware(), 'action' => $route->getActionName()];
        }
        file_put_contents(getenv('APP_BASE_PATH').'/runtime-routes.json', json_encode($routes));

        $a = $this->actor();
        $this->enabled();
        $this->app->instance(BenefitApprovals::class, new ConnectorApprovals);
        config()->set('akubica_connectors.continuity', ['enabled' => true, 'certified' => true]);
        app(\App\Services\Api\V1\MedicalServiceCartService::class)->addBasicPlan($a['user']->customer);
        $c = Coupon::factory()->create(['amount_cents' => 400, 'remaining_cents' => 400]);
        CouponUser::create(['coupon_id' => $c->id, 'user_id' => $a['user']->id]);
        $h = $this->headers($a);
        $base = 'https://local.invalid';
        $record = function ($r, string $id, string $schema) {
            $value = $r->json();
            $file = getenv('APP_BASE_PATH').'/connector-contract.private.jsonl';
            file_put_contents($file, json_encode(['case_id' => $id, 'schema' => $schema, 'http' => $r->getStatusCode(), 'value' => $value])."\n", FILE_APPEND | LOCK_EX);
            chmod($file, 0600);
        };
        $r = $this->getJson($base.'/api/v1/cart/benefits?service_line=medical_services', $h)->assertOk();
        $record($r, 'benefits-query', 'OpaqueBenefitSnapshotResponse');
        $snap = $r->json('data');
        $this->flushHeaders();
        $this->post('/logout');
        $this->post('/login', ['email' => $a['user']->email, 'password' => 'password'])->assertRedirect();
        $this->post('/confirm-password', ['password' => 'password'])->assertRedirect();
        $intent = ['cart_ref' => $snap['cart_ref'], 'cart_version' => $snap['cart_version'], 'target_ref' => $snap['benefits'][0]['benefit_ref'], 'operation' => 'apply', 'text_version' => 'synthetic-connectors-v1'];
        $this->get('/benefits/consent?'.http_build_query($intent))->assertOk()->assertSee('Confirmar esta selección')->assertSee($snap['cart_ref']);
        $r = $this->postJson('/benefits/consent/review', $intent)->assertOk();
        $record($r, 'consent-review', 'FirstPartyConsentReviewResponse');
        $r = $this->postJson('/benefits/consent/confirm', $intent)->assertOk();
        $record($r, 'consent-confirm', 'FirstPartyConsentReceiptResponse');
        $cnf = $r->json('data.confirmation_ref');
        $revocable = $this->postJson('/benefits/consent/confirm', $intent)->assertOk()->json('data.confirmation_ref');
        $r = $this->postJson('/benefits/consent/'.$revocable.'/revoke')->assertOk();
        $record($r, 'consent-revoke', 'FirstPartyConsentRevokedResponse');
        $this->flushHeaders();
        $r = $this->postJson($base.'/api/v1/cart/benefits/applications', ['service_line' => 'medical_services', 'cart_ref' => $snap['cart_ref'], 'cart_version' => $snap['cart_version'], 'benefit_ref' => $snap['benefits'][0]['benefit_ref'], 'confirmation_ref' => $cnf], $h + ['Idempotency-Key' => 'synthetic-contract-apply-01'])->assertCreated();
        $record($r, 'benefits-apply', 'OpaqueBenefitMutationResponse');
        $ap = $r->json('data');
        foreach (['/cart' => 'LeoOpaqueCartResponse', '/checkout/prepare' => 'LeoOpaqueCheckoutPrepareResponse'] as $path => $schema) {
            $r = $this->getJson($base.'/api/v1'.$path.'?service_line=medical_services', $h)->assertOk();
            $record($r, 'continuity'.$path, $schema);
        }
        $snap = $this->getJson($base.'/api/v1/cart/benefits?service_line=medical_services', $h)->assertOk()->json('data');
        $this->flushHeaders();
        $r = $this->postJson('/benefits/consent/confirm', ['cart_ref' => $snap['cart_ref'], 'cart_version' => $snap['cart_version'], 'target_ref' => $ap['application_ref'], 'operation' => 'remove', 'text_version' => 'synthetic-connectors-v1'])->assertOk();
        $cnf = $r->json('data.confirmation_ref');
        $this->flushHeaders();
        $r = $this->postJson($base.'/api/v1/cart/benefits/applications/'.$ap['application_ref'].'/remove', ['service_line' => 'medical_services', 'cart_ref' => $snap['cart_ref'], 'cart_version' => $snap['cart_version'], 'confirmation_ref' => $cnf], $h + ['Idempotency-Key' => 'synthetic-contract-remove-01'])->assertOk();
        $record($r, 'benefits-remove', 'OpaqueBenefitMutationResponse');
        Http::assertNothingSent();
    }

    #[Test]
    public function transport_rollout_blocks_unmapped_legacy_access_for_leo_and_preserves_explicit_native_capability(): void
    {
        $route = collect(app('router')->getRoutes())->first(fn ($r) => $r->uri() === 'api/v1/user/contacts' && in_array('GET', $r->methods()));
        $this->assertNotNull($route);
        $capability = 'api:operation:'.str_replace('.', ':', $route->getName());
        $a = Fixture::issue(User::factory()->withRegularCustomer()->create(), 'native', [$capability]);
        $this->enabled();
        $this->getJson('https://local.invalid/api/v1/user/contacts', $this->headers($a))->assertOk();
        $b = Fixture::issue(User::factory()->withRegularCustomer()->create(), 'leo', [$capability]);
        $this->getJson('https://local.invalid/api/v1/user/contacts', $this->headers($b))->assertForbidden();
        $this->flushHeaders();
        $this->post('/logout');
        $this->post('/login', ['email' => $b['user']->email, 'password' => 'password'])->assertRedirect();
        $this->getJson('https://local.invalid/api/v1/user/contacts')->assertUnauthorized();
        $this->getJson('https://local.invalid/api/v1/user/contacts', $this->headers($a))->assertOk();
        $this->assertSame((int) $b['user']->id, (int) \Illuminate\Support\Facades\Auth::guard('web')->id());
    }

    #[Test]
    public function catalog_limits_fail_closed_without_partial_list_or_unbounded_source_locks(): void
    {
        $a = $this->actor();
        $this->enabled();
        $this->app->instance(BenefitApprovals::class, new ConnectorApprovals);
        app(\App\Services\Api\V1\MedicalServiceCartService::class)->addBasicPlan($a['user']->customer);
        foreach ([1, 2] as $unused) {
            $coupon = Coupon::factory()->create(['amount_cents' => 200, 'remaining_cents' => 200]);
            CouponUser::create(['coupon_id' => $coupon->id, 'user_id' => $a['user']->id]);
        }
        foreach ([['assigned_sources' => 1, 'eligible_options' => 50], ['assigned_sources' => 200, 'eligible_options' => 1]] as $limits) {
            config()->set('akubica_connectors.catalog_limits', $limits);
            $this->getJson('https://local.invalid/api/v1/cart/benefits?service_line=medical_services', $this->headers($a))->assertStatus(503)->assertJsonPath('error.code', 'BENEFIT_SERVICE_UNAVAILABLE');
            $this->assertSame(0, DB::table('opaque_benefit_contexts')->count());
        }
        Http::assertNothingSent();
    }
}
