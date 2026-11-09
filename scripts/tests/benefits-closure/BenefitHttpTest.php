<?php

namespace Famedic\BenefitClosure;

use App\Contracts\Api\V1\Auth\BackendCredentialSource;
use App\Models\Coupon;
use App\Models\CouponUser;
use App\Models\MedicalServiceCart;
use App\Models\MedicalServiceCartBenefit;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Api\V1\Benefits\BenefitApprovals;
use App\Services\Api\V1\Benefits\PendingBenefitApprovals;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Api\V1\Identity\LocalMachineCredentialSource;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture as Fixture;
use Tests\TestCase;

final class LocalBenefitApprovals implements BenefitApprovals
{
    public function __construct(private bool $money = false) {}

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
        return $this->money;
    }

    public function textVersion(): ?string
    {
        return 'synthetic-consent-v1';
    }
}
/** Synthetic credentials/payments; real Laravel controllers, models, encryption and transactions. */
final class BenefitHttpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Queue::fake();
        Notification::fake();
        Http::fake();
        config()->set('famedic.medical_attention_subscription_price_cents', 1000);
        $this->app->instance(BenefitApprovals::class, new LocalBenefitApprovals);
        $this->app->bind(BackendCredentialSource::class, LocalMachineCredentialSource::class);
    }

    private function actor(bool $cart = true): array
    {
        $a = Fixture::issue(User::factory()->withRegularCustomer()->create(), 'leo', ['benefits:read', 'benefits:apply', 'benefits:remove']);
        if ($cart) {
            app(\App\Services\Api\V1\MedicalServiceCartService::class)->addBasicPlan($a['user']->customer);
        }

        return $a;
    }

    private function headers(array $a): array
    {
        return ['Authorization' => 'Bearer '.$a['token']->plainTextToken, 'X-Synthetic-Machine-Credential' => $a['machine']->plainTextToken];
    }

    private function source(array $a, string $kind = 'credit', int $amount = 1500, array $extra = []): Coupon
    {
        $c = Coupon::factory()->create(array_replace(['type' => $kind === 'credit' ? \App\Enums\CouponType::Balance : \App\Enums\CouponType::Coupon, 'amount_cents' => $amount, 'remaining_cents' => $amount], $extra));
        CouponUser::create(['coupon_id' => $c->id, 'user_id' => $a['user']->id]);

        return $c;
    }

    private function snapshot(array $a)
    {
        $this->flushHeaders();

        return $this->getJson('/api/v1/cart/benefits?service_line=medical_services', $this->headers($a));
    }

    private function login(array $a): void
    {
        $this->flushHeaders();
        $this->post('/logout');
        $this->post('/login', ['email' => $a['user']->email, 'password' => 'password'])->assertRedirect();
        $this->post('/confirm-password', ['password' => 'password'])->assertRedirect();
    }

    private function consent(array $a, array $s, string $operation = 'apply', ?string $target = null): string
    {
        $this->login($a);

        return $this->postJson('/benefits/consent/confirm', ['cart_ref' => $s['cart_ref'], 'cart_version' => $s['cart_version'], 'target_ref' => $target ?? $s['benefits'][0]['benefit_ref'], 'operation' => $operation, 'text_version' => 'synthetic-consent-v1'])->assertOk()->json('data.confirmation_ref');
    }

    private function apply(array $a, array $s, ?string $receipt, string $key = 'synthetic-benefit-intent-0001')
    {
        $body = ['service_line' => 'medical_services', 'cart_ref' => $s['cart_ref'], 'cart_version' => $s['cart_version'], 'benefit_ref' => $s['benefits'][0]['benefit_ref']];
        if ($receipt !== null) {
            $body['confirmation_ref'] = $receipt;
        }$this->flushHeaders();

        return $this->postJson('/api/v1/cart/benefits/applications', $body, $this->headers($a) + ['Idempotency-Key' => $key]);
    }

    #[Test]
    public function default_policy_blocks_api_and_operative_consent(): void
    {
        $a = $this->actor();
        $this->source($a);
        $this->app->instance(BenefitApprovals::class, new PendingBenefitApprovals);
        $this->snapshot($a)->assertForbidden()->assertJsonPath('error.code', 'BENEFITS_NOT_ENABLED');
        $this->assertSame(0, DB::table('opaque_benefit_contexts')->count());
        $this->login($a);
        $this->postJson('/benefits/consent/confirm', [])->assertForbidden()->assertJsonPath('error.code', 'CONSENT_ISSUANCE_PENDING_APPROVAL');
        $this->assertSame(0, DB::table('opaque_benefit_states')->where('bucket','confirmations')->count());
    }

    #[Test]
    public function real_catalog_is_opaque_and_get_does_not_consume_or_select(): void
    {
        $a = $this->actor();
        $c = $this->source($a);
        $this->source($a, 'coupon', 300);
        $s = $this->snapshot($a)->assertOk()->json('data');
        $this->assertCount(2, $s['benefits']);
        $this->assertSame(1000, $s['totals']['total_cents']);
        foreach ($s['benefits'] as $o) {
            $this->assertSame(['benefit_ref', 'benefit_type', 'estimated_amount_cents', 'max_applicable_cents', 'reference_expires_at'], array_keys($o));
        }$this->assertSame(1500, (int) $c->fresh()->remaining_cents);
        $this->assertSame(0, MedicalServiceCartBenefit::count());
        $this->assertSame(0, Transaction::count());
    }

    #[Test]
    public function coupon_and_credit_apply_remove_update_real_medical_cart_without_consumption(): void
    {
        foreach (['coupon', 'credit'] as $kind) {
            $a = $this->actor();
            $c = $this->source($a, $kind, 500);
            $s = $this->snapshot($a)->assertOk()->json('data');
            $receipt = $this->consent($a, $s);
            $result = $this->apply($a, $s, $receipt)->assertCreated()->assertJsonPath('data.totals.total_cents', 500)->json('data');
            $this->assertSame(500, MedicalServiceCart::where('customer_id', $a['user']->customer->id)->sole()->total_cents);
            $this->assertSame(500, (int) $c->fresh()->remaining_cents);
            $next = $this->snapshot($a)->assertOk()->json('data');
            $remove = $this->consent($a, $next, 'remove', $result['application_ref']);
            $this->postJson('/api/v1/cart/benefits/applications/'.$result['application_ref'].'/remove', ['service_line' => 'medical_services', 'cart_ref' => $next['cart_ref'], 'cart_version' => $next['cart_version'], 'confirmation_ref' => $remove], $this->headers($a) + ['Idempotency-Key' => 'synthetic-remove-benefit-0001'])->assertOk()->assertJsonPath('data.totals.total_cents', 1000);
            $this->assertSame(500, (int) $c->fresh()->remaining_cents);
        }
    }

    #[Test]
    public function missing_invalid_expired_and_revoked_consent_cannot_apply(): void
    {
        $a = $this->actor();
        $this->source($a);
        $s = $this->snapshot($a)->assertOk()->json('data');
        $this->apply($a, $s, null)->assertUnprocessable()->assertJsonPath('error.code', 'CONFIRMATION_REQUIRED');
        $this->apply($a, $s, \App\Domain\Akubica\Benefits\OpaqueReference::issue('cnf'))->assertNotFound();
        $receipt = $this->consent($a, $s);
        $this->postJson('/benefits/consent/'.$receipt.'/revoke')->assertOk();
        $this->apply($a, $s, $receipt)->assertStatus(409)->assertJsonPath('error.code', 'CONFIRMATION_REVOKED');
        $this->assertSame(0, MedicalServiceCartBenefit::count());
    }

    #[Test]
    public function authenticated_external_consumer_cannot_issue_consent_unilaterally(): void
    {
        $a = $this->actor();
        $this->source($a);
        $s = $this->snapshot($a)->assertOk()->json('data');
        $this->postJson('/benefits/consent/confirm', ['cart_ref' => $s['cart_ref'], 'cart_version' => $s['cart_version'], 'target_ref' => $s['benefits'][0]['benefit_ref'], 'operation' => 'apply', 'text_version' => 'synthetic-consent-v1'], $this->headers($a))->assertUnauthorized();
        $this->assertSame(0, DB::table('opaque_benefit_states')->where('bucket', 'confirmations')->count());
    }

    #[Test]
    public function cart_change_invalidates_consent_even_after_aba(): void
    {
        $a = $this->actor();
        $this->source($a);
        $s = $this->snapshot($a)->assertOk()->json('data');
        $receipt = $this->consent($a, $s);
        $item = \App\Models\MedicalServiceCartItem::sole();
        $item->update(['subtotal_cents' => 1200]);
        $item->update(['subtotal_cents' => 1000]);
        $this->apply($a, $s, $receipt)->assertStatus(409)->assertJsonPath('error.code', 'CART_VERSION_CONFLICT');
    }

    #[Test]
    public function foreign_options_and_cart_are_not_found(): void
    {
        $a = $this->actor();
        $b = $this->actor();
        $this->source($a);
        $this->source($b);
        $sa = $this->snapshot($a)->assertOk()->json('data');
        $sb = $this->snapshot($b)->assertOk()->json('data');
        $receipt = $this->consent($a, $sa);
        $this->apply($b, $sa, $receipt)->assertNotFound();
        $sb['benefits'][0]['benefit_ref'] = $sa['benefits'][0]['benefit_ref'];
        $this->apply($b, $sb, $receipt)->assertNotFound();
        $this->assertSame(0, MedicalServiceCartBenefit::count());
    }

    #[Test]
    public function exact_retry_returns_receipt_without_duplicate_effect_or_consent_reuse(): void
    {
        $a = $this->actor();
        $this->source($a);
        $s = $this->snapshot($a)->assertOk()->json('data');
        $r = $this->consent($a, $s);
        $first = $this->apply($a, $s, $r)->assertCreated()->json('data');
        $second = $this->apply($a, $s, $r)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->json('data');
        $this->assertSame($first, $second);
        $this->assertSame(1, MedicalServiceCartBenefit::count());
    }

    #[Test]
    public function empty_cart_is_controlled_without_creating_cart(): void
    {
        $a = $this->actor(false);
        $this->snapshot($a)->assertStatus(409)->assertJsonPath('error.code', 'EMPTY_CART');
        $this->assertSame(0, MedicalServiceCart::count());
    }

    #[Test]
    public function storage_failure_is_not_success(): void
    {
        $a = $this->actor();
        $this->source($a);
        Schema::rename('opaque_benefit_states', 'fixture_unavailable_states');
        try {
            $this->snapshot($a)->assertStatus(503)->assertJsonPath('error.code', 'BENEFIT_SERVICE_UNAVAILABLE');
        } finally {
            Schema::rename('fixture_unavailable_states', 'opaque_benefit_states');
        }$this->assertSame(0, MedicalServiceCartBenefit::count());
    }

    #[Test]
    public function expired_consent_is_rejected_before_selection(): void
    {
        $a = $this->actor();
        $this->source($a);
        $s = $this->snapshot($a)->assertOk()->json('data');
        $receipt = $this->consent($a, $s);
        $this->travel(121)->seconds();
        $this->apply($a, $s, $receipt)->assertStatus(409)->assertJsonPath('error.code', 'CONFIRMATION_EXPIRED');
        $this->assertSame(0, MedicalServiceCartBenefit::count());
        $this->travelBack();
    }

    #[Test]
    public function eligibility_http_uses_real_coupon_rules_for_both_kinds(): void
    {
        $a = $this->actor();
        foreach (['credit', 'coupon'] as $kind) {
            foreach ([['is_active' => false], ['expires_at' => now()->subMinute()], ['valid_from' => now()->addMinute()], ['min_purchase_cents' => 1001], ['remaining_cents' => 0], ['approval_status' => \App\Enums\CouponApprovalStatus::Rejected]] as $attrs) {
                $this->source($a, $kind, 1500, $attrs);
            }
        } $this->snapshot($a)->assertOk()->assertJsonCount(0, 'data.benefits');
    }

    #[Test]
    public function source_ab_a_invalidates_prepared_intent(): void
    {
        $a = $this->actor();
        $c = $this->source($a);
        $s = $this->snapshot($a)->assertOk()->json('data');
        $r = $this->consent($a, $s);
        $c->update(['remaining_cents' => 1400]);
        $c->update(['remaining_cents' => 1500]);
        $this->apply($a, $s, $r)->assertStatus(409)->assertJsonPath('error.code', 'CONFIRMATION_CONTEXT_CHANGED');
        $this->assertSame(0, MedicalServiceCartBenefit::count());
    }

    #[Test]
    public function opaque_request_rejects_internal_identifiers(): void
    {
        $a = $this->actor();
        $this->source($a);
        $s = $this->snapshot($a)->assertOk()->json('data');
        $r = $this->consent($a, $s);
        $this->postJson('/api/v1/cart/benefits/applications', ['service_line' => 'medical_services', 'cart_ref' => $s['cart_ref'], 'cart_version' => $s['cart_version'], 'benefit_ref' => $s['benefits'][0]['benefit_ref'], 'confirmation_ref' => $r, 'coupon_id' => 123], $this->headers($a) + ['Idempotency-Key' => 'synthetic-extra-field-0001'])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    #[Test]
    public function revoked_consumer_cannot_replay_committed_receipt(): void
    {
        $a = $this->actor();
        $this->source($a);
        $s = $this->snapshot($a)->assertOk()->json('data');
        $r = $this->consent($a, $s);
        $this->apply($a, $s, $r)->assertCreated();
        $a['consumer']->forceFill(['enabled' => false])->save();
        $this->apply($a, $s, $r)->assertForbidden();
        $this->assertSame(1, MedicalServiceCartBenefit::count());
    }

    #[Test]
    public function consent_requires_recent_first_party_password_confirmation(): void
    {
        $a = $this->actor();
        $this->source($a);
        $s = $this->snapshot($a)->assertOk()->json('data');
        $this->flushHeaders();
        $this->post('/login', ['email' => $a['user']->email, 'password' => 'password'])->assertRedirect();
        $this->postJson('/benefits/consent/confirm', ['cart_ref' => $s['cart_ref'], 'cart_version' => $s['cart_version'], 'target_ref' => $s['benefits'][0]['benefit_ref'], 'operation' => 'apply', 'text_version' => 'synthetic-consent-v1'])->assertForbidden()->assertJsonPath('error.code', 'FIRST_PARTY_CONFIRMATION_REQUIRED');
    }

    #[Test]
    public function legacy_selection_is_preserved_and_not_silently_adopted(): void
    {
        $a = $this->actor();
        $c = $this->source($a, 'coupon', 300, ['code' => 'SYNTHETIC-LEGACY-ONLY']);
        app(\App\Services\Api\V1\MedicalServiceCartBenefitService::class)->apply($a['user']->customer, $a['user'], $c->code);
        $this->snapshot($a)->assertStatus(409)->assertJsonPath('error.code', 'LEGACY_SELECTION_REQUIRES_REVIEW');
        $this->assertSame(700, MedicalServiceCart::sole()->total_cents);
        $this->assertSame((int) $c->id, (int) MedicalServiceCartBenefit::sole()->coupon_id);
    }

    private function financeFixture(int $credit = 1500): array
    {
        $a = $this->actor();
        $c = $this->source($a, 'credit', $credit);
        $s = $this->snapshot($a)->assertOk()->json('data');
        $r = $this->consent($a, $s);
        $result = $this->apply($a, $s, $r)->assertCreated()->json('data');
        $payment = Transaction::create(['transaction_amount_cents' => $result['totals']['total_cents'], 'payment_status' => 'captured', 'payment_method' => 'paypal', 'gateway' => 'paypal', 'reference_id' => 'synthetic-only-not-a-real-capture', 'details' => ['customer_id' => $a['user']->customer->id]]);
        $membership = \App\Models\MedicalAttentionSubscription::create(['customer_id' => $a['user']->customer->id, 'price_cents' => 1000, 'type' => \App\Enums\MedicalSubscriptionType::REGULAR, 'start_date' => now(), 'end_date' => now()->addMonth()]);
        $membership->transactions()->attach($payment->id);
        $actor = new \App\Domain\Akubica\Benefits\BenefitActor((int) $a['user']->id, (string) $a['consumer']->id, (string) $a['delegation']->id, (string) $a['channel']->id);

        return [$a, $actor, $c, $s, $result, $payment];
    }

    private function syntheticFinance(): \App\Services\Api\V1\Benefits\MedicalCreditSettlementBridge
    {
        $this->app->instance(BenefitApprovals::class, new LocalBenefitApprovals(true));
        $this->app->instance(\App\Services\Api\V1\Benefits\MedicalPaymentAuthority::class, new class implements \App\Services\Api\V1\Benefits\MedicalPaymentAuthority
        {
            public function confirmed(Transaction $payment, int $customerId, int $cashCents): bool
            {
                return true;
            }

            public function reversed(Transaction $payment, int $customerId): bool
            {
                return true;
            }
        });

        return app(\App\Services\Api\V1\Benefits\MedicalCreditSettlementBridge::class);
    }

    private function rejects(string $code, callable $op): void
    {
        try {
            $op();
            $this->fail('Expected controlled rejection');
        } catch (\App\Domain\Akubica\Benefits\BenefitFailure $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    #[Test]
    public function financial_default_and_missing_payment_authority_fail_closed(): void
    {
        [$a,$actor,$c,$s,$ap,$pay] = $this->financeFixture();
        $op = fn () => app(\App\Services\Api\V1\Benefits\MedicalCreditSettlementBridge::class)->prepare($actor, $s['cart_ref'], $ap['application_ref'], $pay->id);
        $this->rejects('FINANCIAL_WRITES_PENDING_APPROVAL', $op);
        $this->app->instance(BenefitApprovals::class, new LocalBenefitApprovals(true));
        $this->rejects('PURCHASE_NOT_VERIFIED', $op);
        $this->assertSame(1500, (int) $c->fresh()->remaining_cents);
        $this->assertSame(0, DB::table('opaque_benefit_settlements')->count());
    }

    #[Test]
    public function synthetic_confirmed_purchase_partial_debit_retry_and_reversal_use_real_ledger(): void
    {
        [$a,$actor,$c,$s,$ap,$pay] = $this->financeFixture();
        $bridge = $this->syntheticFinance();
        $ref = $bridge->prepare($actor, $s['cart_ref'], $ap['application_ref'], $pay->id);
        $this->assertSame(['debited_cents' => 1000, 'remaining_cents' => 500, 'replayed' => false], $bridge->finalize($actor, $ref));
        $this->assertTrue($bridge->finalize($actor, $ref)['replayed']);
        $this->assertSame(500, (int) $c->fresh()->remaining_cents);
        $this->assertNull(CouponUser::sole()->used_at);
        $this->assertSame(1, DB::table('opaque_benefit_movements')->count());
        $this->assertSame(1, Transaction::where('gateway', 'coupon_balance')->count());
        $pay->update(['payment_status' => 'refunded']);
        $this->assertSame(['restored_cents' => 1000, 'replayed' => false], $bridge->reverse($actor, $ref));
        $this->assertTrue($bridge->reverse($actor, $ref)['replayed']);
        $this->assertSame(1500, (int) $c->fresh()->remaining_cents);
        $this->assertSame(2, DB::table('opaque_benefit_movements')->count());
        $this->rejects('PURCHASE_NOT_VERIFIED', fn () => $bridge->finalize($actor, $ref));
    }

    #[Test]
    public function full_price_payment_cannot_also_debit_credit(): void
    {
        [$a,$actor,$c,$s,$ap,$pay] = $this->financeFixture();
        $pay->update(['transaction_amount_cents' => 1000]);
        $bridge = $this->syntheticFinance();
        $this->rejects('PURCHASE_AMOUNT_CONFLICT', fn () => $bridge->prepare($actor, $s['cart_ref'], $ap['application_ref'], $pay->id));
        $this->assertSame(1500, (int) $c->fresh()->remaining_cents);
    }

    #[Test]
    public function reversal_after_legacy_balance_change_requires_reconciliation(): void
    {
        [$a,$actor,$c,$s,$ap,$pay] = $this->financeFixture();
        $bridge = $this->syntheticFinance();
        $ref = $bridge->prepare($actor, $s['cart_ref'], $ap['application_ref'], $pay->id);
        $bridge->finalize($actor, $ref);
        $c->update(['remaining_cents' => 400]);
        $pay->update(['payment_status' => 'refunded']);
        $this->rejects('RECONCILIATION_REQUIRED', fn () => $bridge->reverse($actor, $ref));
        $this->assertSame(400, (int) $c->fresh()->remaining_cents);
        $this->assertSame(1, DB::table('opaque_benefit_movements')->count());
    }

    private function concurrentApply(bool $sameKey): array
    {
        $this->assertSame('mysql', DB::getDriverName());
        $a = $this->actor();
        $this->source($a);
        $s = $this->snapshot($a)->assertOk()->json('data');
        $r1 = $this->consent($a, $s);
        $r2 = $sameKey ? $r1 : $this->consent($a, $s);
        $rt = getenv('APP_BASE_PATH');
        $prefix = $rt.'/race-'.bin2hex(random_bytes(5));
        $go = $prefix.'.go';
        $jobs = [];
        $env = getenv();
        $env['FAMEDIC_V2_PREPARE_SCHEMA'] = '0';
        try {
            foreach ([$r1, $r2] as $i => $r) {
                $ready = $prefix.'.'.$i.'.ready';
                $body = ['service_line' => 'medical_services', 'cart_ref' => $s['cart_ref'], 'cart_version' => $s['cart_version'], 'benefit_ref' => $s['benefits'][0]['benefit_ref'], 'confirmation_ref' => $r];
                $proc = proc_open([PHP_BINARY, '-d', 'allow_url_fopen=0', __DIR__.'/http-worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
                $this->assertIsResource($proc);
                fwrite($pipes[0], json_encode(['ready' => $ready, 'go' => $go, 'body' => $body, 'headers' => $this->headers($a), 'key' => $sameKey ? 'synthetic-race-same-key-0001' : 'synthetic-race-distinct-key-000'.$i]));
                fclose($pipes[0]);
                $jobs[] = [$proc, $pipes, $ready];
            }
            $deadline = microtime(true) + 15;
            while (! is_file($jobs[0][2]) || ! is_file($jobs[1][2])) {
                if (microtime(true) > $deadline) {
                    throw new \RuntimeException('Synthetic workers failed to reach barrier');
                }usleep(20000);
            }touch($go);
            $results = [];
            foreach ($jobs as [$proc,$pipes,$ready]) {
                $out = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($proc), 'Synthetic worker must exit cleanly');
                $results[] = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            if (! is_file($go)) {
                touch($go);
            }foreach (glob($prefix.'*') as $file) {
                unlink($file);
            }
        }
    }

    #[Test,\PHPUnit\Framework\Attributes\Group('mysql')]
    public function mysql_concurrent_distinct_intents_have_one_effect(): void
    {
        $results = $this->concurrentApply(false);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([201, 409], $statuses);
        $this->assertSame(1, MedicalServiceCartBenefit::count());
        $this->assertSame(0, Transaction::count());
    }

    #[Test,\PHPUnit\Framework\Attributes\Group('mysql')]
    public function mysql_concurrent_exact_retries_have_one_effect(): void
    {
        $results = $this->concurrentApply(true);
        $this->assertSame([201, 201], array_column($results, 'status'));
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['replayed'])));
        $this->assertSame(1, MedicalServiceCartBenefit::count());
    }

    #[Test]
    public function ledger_failure_rolls_back_real_balance_and_credit_transaction(): void
    {
        [$a,$actor,$c,$s,$ap,$pay] = $this->financeFixture();
        $bridge = $this->syntheticFinance();
        $ref = $bridge->prepare($actor, $s['cart_ref'], $ap['application_ref'], $pay->id);
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER synthetic_ledger_failure BEFORE INSERT ON opaque_benefit_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic storage failure'");
        } else {
            DB::unprepared("CREATE TRIGGER synthetic_ledger_failure BEFORE INSERT ON opaque_benefit_movements BEGIN SELECT RAISE(ABORT,'synthetic storage failure'); END");
        }
        try {
            try {
                $bridge->finalize($actor, $ref);
                $this->fail('Expected ledger failure');
            } catch (\Illuminate\Database\QueryException) {
                $this->assertSame(1500, (int) $c->fresh()->remaining_cents);
                $this->assertSame(0, DB::table('opaque_benefit_movements')->count());
                $this->assertSame(0, Transaction::where('gateway', 'coupon_balance')->count());
            }
        } finally {
            DB::unprepared('DROP TRIGGER synthetic_ledger_failure');
        }
        $this->assertSame(500, $bridge->finalize($actor, $ref)['remaining_cents']);
    }

    #[Test]
    public function source_assignment_recreation_invalidates_option(): void
    {
        $a = $this->actor();
        $c = $this->source($a);
        $s = $this->snapshot($a)->assertOk()->json('data');
        $r = $this->consent($a, $s);
        CouponUser::where('coupon_id', $c->id)->delete();
        CouponUser::create(['coupon_id' => $c->id, 'user_id' => $a['user']->id]);
        $this->apply($a, $s, $r)->assertStatus(409)->assertJsonPath('error.code', 'CONFIRMATION_CONTEXT_CHANGED');
    }

    #[Test]
    public function invalidated_selection_can_be_removed_without_consuming_source(): void
    {
        $a = $this->actor();
        $c = $this->source($a);
        $s = $this->snapshot($a)->assertOk()->json('data');
        $r = $this->consent($a, $s);
        $result = $this->apply($a, $s, $r)->assertCreated()->json('data');
        $c->update(['is_active' => false]);
        $next = $this->snapshot($a)->assertOk()->assertJsonPath('data.application.status', 'invalidated')->json('data');
        $remove = $this->consent($a, $next, 'remove', $result['application_ref']);
        $this->postJson('/api/v1/cart/benefits/applications/'.$result['application_ref'].'/remove', ['service_line' => 'medical_services', 'cart_ref' => $next['cart_ref'], 'cart_version' => $next['cart_version'], 'confirmation_ref' => $remove], $this->headers($a) + ['Idempotency-Key' => 'synthetic-invalidated-remove-0001'])->assertOk()->assertJsonPath('data.totals.total_cents', 1000);
        $this->assertSame(0, MedicalServiceCartBenefit::count());
        $this->assertSame(1500, (int) $c->fresh()->remaining_cents);
    }

    #[Test,\PHPUnit\Framework\Attributes\Group('mysql')]
    public function mysql_concurrent_confirmed_purchases_cannot_overdraw_shared_credit(): void
    {
        [$a,$actor,$c,$s,$ap,$pay] = $this->financeFixture();
        $bridge = $this->syntheticFinance();
        $r1 = $bridge->prepare($actor, $s['cart_ref'], $ap['application_ref'], $pay->id);
        $pay2 = $pay->replicate();
        $pay2->save();
        $pay->medicalAttentionSubscriptions()->first()->transactions()->attach($pay2->id);
        $r2 = $bridge->prepare($actor, $s['cart_ref'], $ap['application_ref'], $pay2->id);
        $rt = getenv('APP_BASE_PATH');
        $prefix = $rt.'/finance-race-'.bin2hex(random_bytes(5));
        $go = $prefix.'.go';
        $jobs = [];
        $env = getenv();
        $env['FAMEDIC_V2_PREPARE_SCHEMA'] = '0';
        try {
            foreach ([$r1, $r2] as $i => $ref) {
                $ready = $prefix.'.'.$i.'.ready';
                $proc = proc_open([PHP_BINARY, '-d', 'allow_url_fopen=0', __DIR__.'/http-worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
                $this->assertIsResource($proc);
                fwrite($pipes[0], json_encode(['mode' => 'finalize', 'actor' => [$actor->userId, $actor->consumerId, $actor->delegationId, $actor->channelId], 'purchase_ref' => $ref, 'ready' => $ready, 'go' => $go]));
                fclose($pipes[0]);
                $jobs[] = [$proc, $pipes, $ready];
            }
            $deadline = microtime(true) + 15;
            while (! is_file($jobs[0][2]) || ! is_file($jobs[1][2])) {
                if (microtime(true) > $deadline) {
                    throw new \RuntimeException('Financial workers failed before barrier');
                }usleep(20000);
            }touch($go);
            $statuses = [];
            foreach ($jobs as [$proc,$pipes,$ready]) {
                $out = stream_get_contents($pipes[1]);
                $err = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($proc));
                $statuses[] = json_decode($out, true, 512, JSON_THROW_ON_ERROR)['status'];
            }sort($statuses);
            $this->assertSame([200, 409], $statuses);
            $this->assertSame(500, (int) $c->fresh()->remaining_cents);
            $this->assertSame(1, DB::table('opaque_benefit_movements')->count());
        } finally {
            if (! is_file($go)) {
                touch($go);
            }foreach (glob($prefix.'*') as $file) {
                unlink($file);
            }
        }
    }

    #[Test]
    public function registered_candidate_pipeline_preserves_native_legacy_and_denies_leo(): void
    {
        // Uses registered middleware from the actual candidate route tree.
        $native = Fixture::issue(User::factory()->withRegularCustomer()->create(), 'native', ['legacy-benefits:read', 'legacy-benefits:write']);
        app(\App\Services\Api\V1\MedicalServiceCartService::class)->addBasicPlan($native['user']->customer);
        $c = $this->source($native, 'coupon', 300, ['code' => 'SYNTHETIC-NATIVE-LEGACY']);
        $this->getJson('/api/v1/cart/medical-benefit?service_line=medical_services', $this->headers($native))->assertOk();
        $this->postJson('/api/v1/cart/medical-benefit', ['service_line' => 'medical_services', 'code' => $c->code], $this->headers($native) + ['Idempotency-Key' => 'synthetic-legacy-native-0001'])->assertOk();
        $this->assertSame(700, MedicalServiceCart::sole()->total_cents);
        $this->deleteJson('/api/v1/cart/medical-benefit', ['service_line' => 'medical_services'], $this->headers($native) + ['Idempotency-Key' => 'synthetic-legacy-remove-0001'])->assertOk();
        $this->assertSame(1000, MedicalServiceCart::sole()->total_cents);
        $leo = $this->actor(false);
        $this->getJson('/api/v1/cart/medical-benefit?service_line=medical_services', $this->headers($leo))->assertForbidden();
    }

    #[Test]
    public function mixed_cash_credit_exact_exhaustion_and_reversal_use_real_assignment(): void
    {
        [$a,$actor,$c,$s,$ap,$pay] = $this->financeFixture(500);
        $this->assertSame(500, (int) $pay->transaction_amount_cents);
        $bridge = $this->syntheticFinance();
        $ref = $bridge->prepare($actor, $s['cart_ref'], $ap['application_ref'], $pay->id);
        $this->assertSame(0, $bridge->finalize($actor, $ref)['remaining_cents']);
        $this->assertNotNull(CouponUser::sole()->used_at);
        $pay->update(['payment_status' => 'refunded']);
        $bridge->reverse($actor, $ref);
        $this->assertSame(500, (int) $c->fresh()->remaining_cents);
        $this->assertNull(CouponUser::sole()->used_at);
    }

    #[Test]
    public function pending_payment_is_rejected_even_with_synthetic_authority(): void
    {
        [$a,$actor,$c,$s,$ap,$pay] = $this->financeFixture();
        $pay->update(['payment_status' => 'pending']);
        $bridge = $this->syntheticFinance();
        $this->rejects('PURCHASE_NOT_VERIFIED', fn () => $bridge->prepare($actor, $s['cart_ref'], $ap['application_ref'], $pay->id));
        $this->assertSame(1500, (int) $c->fresh()->remaining_cents);
    }

    #[Test]
    public function persisted_references_consents_and_snapshots_are_encrypted(): void
    {
        $a = $this->actor();
        $this->source($a);
        $s = $this->snapshot($a)->assertOk()->json('data');
        $r = $this->consent($a, $s);
        $this->apply($a, $s, $r)->assertCreated();
        $this->assertGreaterThan(0, DB::table('opaque_benefit_states')->where('bucket', 'snapshots')->count());
        foreach (DB::table('opaque_benefit_states')->get() as $row) {
            $this->assertStringNotContainsString($s['cart_ref'], $row->ciphertext);
            $this->assertStringNotContainsString($r, $row->ciphertext);
            $this->assertIsArray(json_decode(\Illuminate\Support\Facades\Crypt::decryptString($row->ciphertext), true));
        }
    }
}
