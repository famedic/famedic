<?php

namespace Famedic\IdentityClosure;

use App\Contracts\Api\V1\Auth\BackendCredentialSource;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\Address;
use App\Models\AkubicaCheckoutLink;
use App\Models\ApiConsumerCredential;
use App\Models\ApiConsumerTokenBinding;
use App\Models\Contact;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\MedicalServiceCartItem;
use App\Models\User;
use App\Services\Api\V1\Auth\ConsumerTokenBindingWriter;
use App\Services\Api\V1\Auth\PaymentLinkAccessPolicy;
use App\Services\Api\V1\Auth\UnavailableBackendCredentialSource;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Api\V1\Identity\LocalMachineCredentialSource;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture as Fixture;
use Tests\TestCase;

/** Real Laravel HTTP + hashed machine/user PATs + persistent registry in RAM.
 * ONLY the credential transport is test-specific, NOT a verifier mock.
 */
final class RegisteredIdentityHttpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Queue::fake();
        Notification::fake();
        Http::fake();
        config()->set('api_v1.idempotency.enabled', true);
        $this->app->bind(BackendCredentialSource::class, LocalMachineCredentialSource::class);
        // Real candidate routes now install authorization; no test-only middleware injection.

    }

    private function actor(string $kind = 'leo', ?array $caps = null): array
    {
        return Fixture::issue(User::factory()->withRegularCustomer()->create(), $kind, $caps);
    }

    private function headers(array $a): array
    {
        return ['Authorization' => 'Bearer '.$a['token']->plainTextToken, 'X-Synthetic-Machine-Credential' => $a['machine']->plainTextToken];
    }

    private function payment(array $a, array $body = ['brand' => 'olab'], string $key = 'synthetic-identity-payment'): mixed
    {
        $this->flushHeaders();

        return $this->postJson('/api/v1/checkout/payment-link', $body, $this->headers($a) + ['Idempotency-Key' => $key]);
    }

    private function ready(array $a): void
    {
        $u = $a['user'];
        addOlabCartItemReadyForPaymentLink($u);
        $contact = Contact::factory()->for($u->customer)->create(['birth_date' => '1990-01-01', 'gender' => Gender::MALE]);
        $address = Address::factory()->for($u->customer)->create(['city' => 'Synthetic', 'state' => 'Synthetic']);
        LaboratoryCheckoutDraft::create(['customer_id' => $u->customer->id, 'laboratory_brand' => LaboratoryBrand::OLAB, 'contact_id' => $contact->id, 'address_id' => $address->id, 'checkout_step' => 'confirmation']);
    }

    private function noPayments(int $links = 0): void
    {
        $this->assertSame($links, AkubicaCheckoutLink::count());
        $this->assertSame(0, Http::recorded()->count());
    }

    #[Test]
    public function atix_registered_machine_and_user_credentials_can_read_approved_membership(): void
    {
        $a = $this->actor('leo', ['memberships:read']);
        $this->getJson('/api/v1/memberships/status', $this->headers($a))->assertOk()->assertJsonPath('data.status', 'none');
        $this->noPayments();
    }

    #[Test]
    public function leo_payment_deny_precedes_readiness_brand_wildcard_and_spoofed_identity(): void
    {
        $a = $this->actor();
        $this->ready($a);
        $draft = LaboratoryCheckoutDraft::sole()->getAttributes();
        foreach ([['brand' => 'olab'], ['brand' => 'swisslab'], ['brand' => 'jenner'], ['brand' => 'liacsa'], ['brand' => 'azteca'], [], ['service_line' => 'medical_services']] as $body) {
            $this->payment($a, $body)->assertForbidden()->assertJsonPath('error.code', 'LEO_PAYMENT_LINK_FORBIDDEN');
        }
        $a['token']->accessToken->forceFill(['abilities' => ['*', PaymentLinkAccessPolicy::CAPABILITY]])->save();
        $this->withHeaders(['X-Client' => 'native', 'User-Agent' => 'native'])->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab', 'consumer_id' => 'native'], $this->headers($a))->assertForbidden()->assertJsonPath('error.code', 'LEO_PAYMENT_LINK_FORBIDDEN');
        $this->assertSame($draft, LaboratoryCheckoutDraft::sole()->getAttributes());
        $this->noPayments();
    }

    #[Test]
    public function leo_is_denied_all_six_legacy_benefit_routes_and_checkout_draft(): void
    {
        $a = $this->actor();
        foreach (['/api/v1/cart/medical-benefit', '/api/v1/cart/coupon'] as $path) {
            foreach (['GET', 'POST', 'DELETE'] as $method) {
                $this->flushHeaders();
                $this->json($method, $path, [], $this->headers($a) + ['Idempotency-Key' => 'synthetic-legacy-denial'])->assertForbidden()->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
            }
        }
        $this->postJson('/api/v1/checkout/draft', ['brand' => 'olab'], $this->headers($a))->assertForbidden();
        $this->noPayments();
    }

    #[Test]
    public function native_verified_client_creates_one_link_and_replay_creates_no_duplicate(): void
    {
        $a = $this->actor('native');
        $this->ready($a);
        $first = $this->payment($a)->assertOk();
        $this->payment($a)->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.payment_link.url', $first->json('data.payment_link.url'));
        $this->noPayments(1);
    }

    #[Test]
    public function native_without_explicit_payment_capability_is_denied(): void
    {
        $a = $this->actor('native', ['memberships:read']);
        $this->ready($a);
        $this->payment($a)->assertForbidden()->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
        $this->noPayments();
    }

    #[Test]
    public function native_pat_with_verified_leo_machine_is_mismatch_not_native(): void
    {
        $a = $this->actor('native');
        $b = $this->actor('leo');
        $a['machine'] = $b['machine'];
        $this->payment($a)->assertForbidden()->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
        $this->noPayments();
    }

    #[Test]
    public function own_medical_cart_add_remove_and_foreign_cart_isolation_are_enforced(): void
    {
        $a = $this->actor('leo');
        $b = $this->actor('leo');
        $body = ['service_line' => 'medical_services', 'item_type' => 'membership', 'membership_plan_code' => 'basic_annual', 'quantity' => 1];
        $this->postJson('/api/v1/cart/items', $body, $this->headers($a))->assertCreated();
        $item = MedicalServiceCartItem::sole();
        $this->flushHeaders();
        $this->getJson('/api/v1/cart?service_line=medical_services', $this->headers($b))->assertOk()->assertJsonCount(0, 'data.items');
        $this->deleteJson('/api/v1/cart/items/'.$item->id, [], $this->headers($b))->assertForbidden();
        $this->assertSame(1, MedicalServiceCartItem::count());
        $this->flushHeaders();
        $this->deleteJson('/api/v1/cart/items/'.$item->id, [], $this->headers($a))->assertOk();
        $this->assertSame(0, MedicalServiceCartItem::count());
        $this->noPayments();
    }

    #[Test]
    public function revoked_backend_credential_is_rejected(): void
    {
        $a = $this->actor('native');
        $a['credential']->forceFill(['revoked_at' => now()])->save();
        $this->payment($a)->assertForbidden();
        $this->noPayments();
    }

    #[Test]
    public function deleted_backend_pat_is_rejected_and_registry_retains_tombstone(): void
    {
        $a = $this->actor('native');
        $a['machine']->accessToken->delete();
        $this->assertNull($a['credential']->fresh()->personal_access_token_id);
        $this->payment($a)->assertForbidden();
        $this->noPayments();
    }

    #[Test]
    public function disabled_consumer_is_rejected(): void
    {
        $a = $this->actor('native');
        $a['consumer']->forceFill(['enabled' => false])->save();
        $this->payment($a)->assertForbidden();
        $this->noPayments();
    }

    #[Test]
    public function expired_delegation_is_rejected(): void
    {
        $a = $this->actor('native');
        $a['delegation']->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->payment($a)->assertForbidden();
        $this->noPayments();
    }

    #[Test]
    public function revocation_precedes_cached_success_replay(): void
    {
        $a = $this->actor('native');
        $this->ready($a);
        $this->payment($a)->assertOk();
        $a['delegation']->forceFill(['revoked_at' => now()])->save();
        $this->payment($a)->assertForbidden()->assertHeaderMissing('Idempotency-Replayed');
        $this->noPayments(1);
    }

    #[Test]
    public function incoherent_delegation_customer_is_rejected(): void
    {
        $a = $this->actor('native');
        $b = $this->actor('native');
        $a['delegation']->forceFill(['customer_id' => $b['user']->customer->id])->save();
        $this->payment($a)->assertForbidden();
        $this->noPayments();
    }

    #[Test]
    public function web_session_cannot_substitute_for_delegated_user_pat(): void
    {
        $a = $this->actor('native');
        config()->set('auth.defaults.guard', 'web');
        $this->post('/login', ['email' => $a['user']->email, 'password' => 'password'])->assertRedirect();
        $this->flushHeaders();
        $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], ['X-Synthetic-Machine-Credential' => $a['machine']->plainTextToken])->assertUnauthorized();
        $this->noPayments();
    }

    #[Test]
    public function unclassified_wildcard_and_client_name_do_not_grant_identity(): void
    {
        $a = $this->actor('native');
        $unclassified = $a['user']->createToken('native', ['*']);
        $a['token'] = $unclassified;
        $this->payment($a)->assertForbidden()->assertJsonPath('error.code', 'CONSUMER_NOT_AUTHORIZED');
        $this->noPayments();
    }

    #[Test]
    public function storage_failure_rejects_before_replaying_success(): void
    {
        $a = $this->actor('native');
        $this->ready($a);
        $this->payment($a)->assertOk();
        Schema::rename('api_consumer_token_bindings', 'fixture_identity_bindings');
        try {
            $this->payment($a)->assertForbidden()->assertHeaderMissing('Idempotency-Replayed');
            $this->noPayments(1);
        } finally {
            Schema::rename('fixture_identity_bindings', 'api_consumer_token_bindings');
        }
    }

    #[Test]
    public function public_headers_and_invalid_machine_bearer_are_not_identity(): void
    {
        $a = $this->actor('native');
        $a['machine']->plainTextToken = 'not-an-issued-machine-credential';
        $this->payment($a)->assertForbidden();
        $this->noPayments();
    }

    #[Test]
    public function production_default_source_ignores_even_valid_test_header(): void
    {
        $a = $this->actor('native');
        $this->app->bind(BackendCredentialSource::class, UnavailableBackendCredentialSource::class);
        $this->payment($a)->assertForbidden();
        $this->noPayments();
    }

    #[Test]
    public function unauthorized_enrollment_and_duplicate_binding_are_denied(): void
    {
        $a = $this->actor();
        $writer = app(ConsumerTokenBindingWriter::class);
        $other = User::factory()->create();
        try {
            $writer->bind($other, $a['token']->accessToken, $a['delegation'], [], now()->addMinutes(1));
            $this->fail('Enrollment must be explicitly authorized');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertTrue(true);
        }
        try {
            $writer->bind($a['issuer'], $a['token']->accessToken, $a['delegation'], [], now()->addMinutes(1));
            $this->fail('Duplicate binding forbidden');
        } catch (\DomainException) {
            $this->assertTrue(true);
        }
        $this->assertSame(1, ApiConsumerTokenBinding::count());
    }

    #[Test]
    public function retired_native_context_cannot_replay_link_when_consumer_is_now_leo(): void
    {
        $a = $this->actor('native');
        $this->ready($a);
        $this->payment($a)->assertOk();
        $a['consumer']->forceFill(['kind' => 'leo'])->save();
        $this->payment($a)->assertForbidden()->assertJsonPath('error.code', 'LEO_PAYMENT_LINK_FORBIDDEN')->assertHeaderMissing('Idempotency-Replayed');
        $this->noPayments(1);
    }

    #[Test]
    public function revoked_and_expired_user_pat_are_unauthenticated(): void
    {
        $a = $this->actor('native');
        $a['token']->accessToken->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->payment($a)->assertUnauthorized();
        $a['token']->accessToken->delete();
        $this->payment($a)->assertUnauthorized();
        $this->assertNull($a['binding']->fresh()->personal_access_token_id);
        $this->noPayments();
    }

    #[Test]
    public function machine_credential_never_substitutes_for_user_pat(): void
    {
        $a = $this->actor('native');
        $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], ['Authorization' => 'Bearer '.$a['machine']->plainTextToken, 'X-Synthetic-Machine-Credential' => $a['machine']->plainTextToken])->assertUnauthorized();
        $this->noPayments();
    }

    #[Test]
    public function backend_global_expiration_and_literal_scope_are_enforced(): void
    {
        $a = $this->actor('native');
        config()->set('sanctum.expiration', 1440);
        $a['machine']->accessToken->forceFill(['created_at' => now()->subMinutes(1441)])->save();
        $this->payment($a)->assertForbidden();
        $a['machine']->accessToken->forceFill(['created_at' => now(), 'abilities' => ['*', 'client:authenticate']])->save();
        $this->payment($a)->assertForbidden();
        $this->noPayments();
    }

    #[Test]
    public function ambiguous_credential_registration_is_rejected_by_unique_constraint(): void
    {
        $a = $this->actor('native');
        try {
            ApiConsumerCredential::forceCreate(['consumer_id' => $a['consumer']->id, 'channel_id' => $a['channel']->id, 'personal_access_token_id' => $a['machine']->accessToken->id, 'enabled' => true, 'expires_at' => now()->addHour()]);
            $this->fail('Duplicate authenticated credential must be rejected');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame(1, ApiConsumerCredential::count());
        }
        $this->getJson('/api/v1/memberships/status', $this->headers($a))->assertOk();
        $this->noPayments();
    }

    #[Test]
    public function leo_administrator_role_never_grants_payment(): void
    {
        $a = $this->actor('leo');
        $role = \App\Models\Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
        $admin = \App\Models\Administrator::factory()->create(['user_id' => $a['user']->id]);
        $admin->assignRole($role);
        $this->assertTrue($admin->hasRole('superadmin'));
        $this->payment($a)->assertForbidden()->assertJsonPath('error.code', 'LEO_PAYMENT_LINK_FORBIDDEN');
        $this->noPayments();
    }

    #[Test]
    public function direct_payment_action_requires_current_verified_authorization(): void
    {
        $a = $this->actor('leo');
        $request = \Illuminate\Http\Request::create('/api/v1/checkout/payment-link', 'POST');
        foreach ($this->headers($a) as $key => $value) {
            $request->headers->set($key, $value);
        }
        $previous = app('request');
        app()->instance('request', $request);
        try {
            try {
                app(\App\Actions\Api\V1\GenerateAkubicaCheckoutPaymentLinkAction::class)($a['user']->customer, LaboratoryBrand::OLAB, 15);
                $this->fail('Direct bypass must be denied');
            } catch (\App\Exceptions\PaymentLinkAuthorizationDenied $e) {
                $this->assertSame(403, $e->getResponse()->getStatusCode());
            }
        } finally {
            app()->instance('request', $previous);
        }$this->noPayments();
    }

    #[Test]
    public function same_customer_different_registered_consumers_do_not_share_payment_receipts(): void
    {
        $a = $this->actor('native');
        $b = Fixture::issue($a['user'], 'native');
        $this->ready($a);
        $first = $this->payment($a, ['brand' => 'olab'], 'synthetic-shared-consumer-key')->assertOk();
        $second = $this->payment($b, ['brand' => 'olab'], 'synthetic-shared-consumer-key')->assertOk()->assertHeaderMissing('Idempotency-Replayed');
        $this->assertNotSame($first->json('data.payment_link.url'), $second->json('data.payment_link.url'));
        $this->noPayments(2);
    }

    #[Test]
    public function expired_internal_scope_never_falls_back_to_customer_receipt_namespace(): void
    {
        $a = $this->actor('native');
        $user = $a['user']->withAccessToken($a['token']->accessToken);
        $context = app(\App\Contracts\Api\V1\Auth\ConsumerBindingRepository::class)->findByTokenId($a['token']->accessToken->id);
        $expired = new \App\Services\Api\V1\Auth\ConsumerAuthorizationContext(...array_replace(get_object_vars($context), ['expiresAt' => now()->subSecond()->timestamp]));
        $request = \Illuminate\Http\Request::create('/api/v1/checkout/payment-link', 'POST');
        $request->setUserResolver(fn () => $user);
        \App\Services\Api\V1\Auth\ConsumerRequestScope::bind($request, $expired);
        try {
            app(\App\Services\Api\V1\Idempotency\IdempotencyActorResolver::class)->resolve($request);
            $this->fail('Expired scope must not fall back');
        } catch (\App\Exceptions\PaymentLinkAuthorizationDenied $e) {
            $this->assertSame(403, $e->getResponse()->getStatusCode());
        }
    }

    #[Test]
    public function internal_enrollment_defaults_disabled_and_never_grants_pending_capabilities(): void
    {
        $a = $this->actor('native');
        $writer = app(\App\Services\Api\V1\Auth\ConsumerRegistryWriter::class);
        $consumer = $writer->register($a['issuer'], 'leo', ['memberships:read'], now()->addMinutes(20));
        $this->assertFalse($consumer->enabled);
        $channel = $writer->channel($a['issuer'], $consumer, now()->addMinutes(10));
        $this->assertFalse($channel->enabled);
        $before = \Laravel\Sanctum\PersonalAccessToken::count();
        try {
            app(\App\Services\Api\V1\Auth\ConsumerMachineCredentialIssuer::class)->issue($a['issuer'], $channel, now()->addMinute());
            $this->fail('Disabled enrollment cannot issue usable credentials');
        } catch (\DomainException) {
            $this->assertSame($before, \Laravel\Sanctum\PersonalAccessToken::count());
        }

        $delegation = $writer->delegate($a['issuer'], $channel, $a['user'], ['memberships:read'], now()->addMinutes(5));
        $this->assertFalse($delegation->enabled);
        try {
            $writer->register($a['issuer'], 'leo', [PaymentLinkAccessPolicy::CAPABILITY], now()->addMinutes(5));
            $this->fail('Leo payment grant forbidden');
        } catch (\DomainException) {
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function registry_migration_round_trip_is_isolated_and_orphan_credentials_never_authorize(): void
    {
        $a = $this->actor('native');
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_11_08_210000_create_api_consumer_identity_registry.php';
        $benefits = require dirname(__DIR__, 3).'/database/migrations/2026_11_08_220000_create_opaque_medical_benefit_store.php';
        // Reverse dependencies first; assertions about identity revocation remain unchanged.
        $reservations = require dirname(__DIR__, 3).'/database/migrations/2026_11_09_010000_create_medical_credit_reservations.php';
        $extensions = [];
        foreach (['2026_11_09_000001_create_consumer_connector_authorities.php', '2026_11_09_000002_bind_otp_backend_challenges.php', '2026_11_09_020000_create_medical_purchase_intents.php', '2026_11_09_030000_type_medical_benefit_movements.php', '2026_11_09_040000_create_benefit_consent_texts.php', '2026_11_10_000001_add_settlement_delivery_receipts.php', '2026_11_10_000002_record_consumer_channel_revocation.php', '2026_11_10_000003_create_first_party_medical_checkouts.php'] as $file) {
            $extensions[] = require dirname(__DIR__, 3).'/database/migrations/'.$file;
        }
        foreach (array_reverse($extensions) as $extension) {
            $extension->down();
        }
        $reservations->down();
        $benefits->down();
        $migration->down();
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('personal_access_tokens'));
        $migration->up();
        $benefits->up();
        $reservations->up();
        foreach ($extensions as $extension) {
            $extension->up();
        }
        $this->assertSame(0, \App\Models\ApiConsumer::count());
        $this->payment($a)->assertForbidden();
        $this->noPayments();
    }

    #[Test]
    public function historical_customer_receipt_requires_reconciliation_never_duplicate_link(): void
    {
        $a = $this->actor('native');
        $this->ready($a);
        $this->payment($a)->assertOk();
        $receipt = \App\Models\Api\V1\IdempotencyRecord::sole();
        $receipt->forceFill(['actor_key' => 'customer:'.$a['user']->customer->id])->save();
        $this->payment($a)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_OPERATION_UNCERTAIN')->assertHeaderMissing('Idempotency-Replayed');
        $this->noPayments(1);
    }
}
