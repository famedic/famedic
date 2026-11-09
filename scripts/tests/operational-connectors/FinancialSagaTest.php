<?php

namespace Famedic\OperationalConnectors;

use App\Contracts\Api\V1\Auth\BackendCredentialSource;
use App\Domain\Akubica\Benefits\BenefitActor;
use App\Domain\Akubica\Benefits\BenefitFailure;
use App\Models\Coupon;
use App\Models\CouponUser;
use App\Models\MedicalAttentionSubscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Api\V1\Auth\TlsMachinePatCredentialSource;
use App\Services\Api\V1\Benefits\BenefitApprovals;
use App\Services\MedicalCheckout\MedicalPurchaseSaga;
use App\Services\MedicalCheckout\RecordedMedicalPaymentAuthority;
use App\Services\PayPalService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture as Fixture;
use Tests\TestCase;

final class FundingApprovals implements BenefitApprovals
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
        return true;
    }

    public function textVersion(): ?string
    {
        return 'synthetic-financial-v1';
    }
}
final class FinancialSagaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Queue::fake();
        Notification::fake();
        Http::fake();
        config()->set('famedic.medical_attention_subscription_price_cents', 1000);
        config()->set('akubica_connectors.transport', ['enabled' => true, 'certified' => true]);
        config()->set('akubica_connectors.financial', ['enabled' => true, 'certified' => true, 'legacy_writers_coordinated' => true]);
        config()->set('akubica_connectors.providers.paypal.certified', true);
        config()->set('akubica_connectors.paypal_merchant_id', 'synthetic-merchant');
        $this->app->bind(BackendCredentialSource::class, TlsMachinePatCredentialSource::class);
        $this->app->instance(BenefitApprovals::class, new FundingApprovals);
        Gate::define('purchaseMedicalBenefits', fn (User $u, BenefitActor $a) => (int) $u->id === $a->userId);
        Gate::define('reverseMedicalBenefits', fn (User $u, BenefitActor $a) => (int) $u->id === $a->userId);
    }

    private function fixture(int $credit = 400, string $kind = 'credit'): array
    {
        $a = Fixture::issue(User::factory()->withRegularCustomer()->create(), 'leo', ['benefits:read', 'benefits:apply', 'benefits:remove']);
        app(\App\Services\Api\V1\MedicalServiceCartService::class)->addBasicPlan($a['user']->customer);
        $c = Coupon::factory()->create(['type' => $kind === 'credit' ? \App\Enums\CouponType::Balance : \App\Enums\CouponType::Coupon, 'amount_cents' => $credit, 'remaining_cents' => $credit]);
        CouponUser::create(['coupon_id' => $c->id, 'user_id' => $a['user']->id]);
        $h = ['Authorization' => 'Bearer '.$a['token']->plainTextToken, 'X-Consumer-Credential' => $a['machine']->plainTextToken];
        $s = $this->getJson('https://local.invalid/api/v1/cart/benefits?service_line=medical_services', $h)->assertOk()->json('data');
        $this->flushHeaders();
        $this->post('/logout');
        $this->post('/login', ['email' => $a['user']->email, 'password' => 'password'])->assertRedirect();
        $this->post('/confirm-password', ['password' => 'password'])->assertRedirect();
        $cnf = $this->postJson('/benefits/consent/confirm', ['cart_ref' => $s['cart_ref'], 'cart_version' => $s['cart_version'], 'target_ref' => $s['benefits'][0]['benefit_ref'], 'operation' => 'apply', 'text_version' => 'synthetic-financial-v1'])->assertOk()->json('data.confirmation_ref');
        $this->flushHeaders();
        $app = $this->postJson('https://local.invalid/api/v1/cart/benefits/applications', ['service_line' => 'medical_services', 'cart_ref' => $s['cart_ref'], 'cart_version' => $s['cart_version'], 'benefit_ref' => $s['benefits'][0]['benefit_ref'], 'confirmation_ref' => $cnf], $h + ['Idempotency-Key' => 'synthetic-financial-select-01'])->assertCreated()->json('data');
        $actor = new BenefitActor((int) $a['user']->id, (string) $a['consumer']->id, (string) $a['delegation']->id, (string) $a['channel']->id);

        return [$a, $actor, $c, $s, $app];
    }

    private function begin(array $f, string $provider = 'paypal'): array
    {
        return app(\App\Actions\MedicalAttention\PurchaseRegularSubscriptionAction::class)->beginBenefitPurchase($f[1], $f[3]['cart_ref'], $f[4]['application_ref'], 'synthetic-purchase-intent-01', $provider);
    }

    private function order(string $ref, string $amount = '6.00', string $merchant = 'synthetic-merchant'): array
    {
        return ['id' => 'synthetic-order', 'status' => 'COMPLETED', 'purchase_units' => [['custom_id' => $ref, 'payee' => ['merchant_id' => $merchant], 'amount' => ['value' => $amount, 'currency_code' => 'MXN'], 'payments' => ['captures' => [['id' => 'synthetic-capture', 'status' => 'COMPLETED', 'final_capture' => true, 'amount' => ['value' => $amount, 'currency_code' => 'MXN']]]]]]];
    }

    private function paypal(string $ref, string $amount = '6.00'): void
    {
        $mock = \Mockery::mock(PayPalService::class);
        $mock->shouldReceive('createOrder')->once()->with(6, 'MXN', $ref, 'Famedic membresía médica')->andReturn(['order_id' => 'synthetic-order']);
        $mock->shouldReceive('getOrder')->andReturn($this->order($ref, $amount));
        $this->app->instance(PayPalService::class, $mock);
    }

    private function rejects(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected denial');
        } catch (BenefitFailure $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    #[Test]
    public function mixed_payment_reconciles_before_debit_and_fulfills_exactly_once(): void
    {
        $f = $this->fixture();
        $p = $this->begin($f);
        $this->assertSame(600, $p['cash_cents']);
        $this->assertSame($p, $this->begin($f));
        $this->paypal($p['purchase_ref']);
        $svc = app(MedicalPurchaseSaga::class);
        $svc->start($f[1], $p['purchase_ref']);
        $this->assertSame(400, (int) $f[2]->fresh()->remaining_cents);
        $this->assertSame(0, MedicalAttentionSubscription::count());
        $done = $svc->reconcile($f[1], $p['purchase_ref']);
        $this->assertSame('fulfilled', $done['state']);
        $this->assertSame(0, (int) $f[2]->fresh()->remaining_cents);
        $this->assertSame($done, $svc->reconcile($f[1], $p['purchase_ref']));
        $this->assertSame(1, DB::table('opaque_benefit_movements')->where('kind', 'debit')->count());
        $this->assertSame(1, MedicalAttentionSubscription::count());
        $this->assertSame(1000, (int) MedicalAttentionSubscription::sole()->price_cents);
        $this->assertSame(1, DB::table('medical_settlement_outbox')->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function total_credit_funding_never_calls_gateway_and_keeps_remainder(): void
    {
        $f = $this->fixture(1500);
        $p = $this->begin($f);
        $this->assertSame(0, $p['cash_cents']);
        $this->app->instance(PayPalService::class, \Mockery::mock(PayPalService::class));
        $svc = app(MedicalPurchaseSaga::class);
        $done = $svc->start($f[1], $p['purchase_ref']);
        $this->assertSame('fulfilled', $done['state']);
        $this->assertSame(500, (int) $f[2]->fresh()->remaining_cents);
        $this->assertSame('internal_funding', Transaction::where('reference_id', $p['purchase_ref'])->sole()->payment_status);
        $this->assertSame(1, MedicalAttentionSubscription::count());
        $this->assertSame(1000, (int) MedicalAttentionSubscription::sole()->price_cents);
        $reversed = $svc->reverse($f[1], $p['purchase_ref']);
        $this->assertSame('reversed', $reversed['state']);
        $this->assertSame(1500, (int) $f[2]->fresh()->remaining_cents);
        $this->assertSame($reversed, $svc->reverse($f[1], $p['purchase_ref']));
        Http::assertNothingSent();
    }

    #[Test]
    public function wrong_provider_amount_never_debits_or_activates_and_holds_funds(): void
    {
        $f = $this->fixture();
        $p = $this->begin($f);
        $this->paypal($p['purchase_ref'], '5.99');
        $svc = app(MedicalPurchaseSaga::class);
        $svc->start($f[1], $p['purchase_ref']);
        $r = $svc->reconcile($f[1], $p['purchase_ref']);
        $this->assertSame('needs_review', $r['state']);
        $this->assertSame(400, (int) $f[2]->fresh()->remaining_cents);
        $this->assertSame(0, MedicalAttentionSubscription::count());
        $this->assertSame('needs_review', DB::table('medical_credit_reservations')->sole()->state);
    }

    #[Test]
    public function transaction_status_is_not_payment_authority(): void
    {
        $f = $this->fixture();
        $p = $this->begin($f);
        $tx = Transaction::where('reference_id', $p['purchase_ref'])->sole();
        $tx->update(['payment_status' => 'captured']);
        $this->assertFalse(app(RecordedMedicalPaymentAuthority::class)->confirmed($tx, (int) $f[0]['user']->customer->id, 600));
        $this->assertSame(0, MedicalAttentionSubscription::count());
    }

    #[Test]
    public function gateway_timeout_does_not_retry_charge_or_release_funds(): void
    {
        $f = $this->fixture();
        $p = $this->begin($f);
        $m = \Mockery::mock(PayPalService::class);
        $m->shouldReceive('createOrder')->once()->andThrow(new \RuntimeException('Synthetic timeout'));
        $this->app->instance(PayPalService::class, $m);
        $svc = app(MedicalPurchaseSaga::class);
        $this->rejects('PAYMENT_OUTCOME_UNCERTAIN', fn () => $svc->start($f[1], $p['purchase_ref']));
        $this->assertSame('needs_review', $svc->start($f[1], $p['purchase_ref'])['state']);
        $this->rejects('PAYMENT_OUTCOME_UNCERTAIN', fn () => $svc->cancelUncharged($f[1], $p['purchase_ref']));
        $this->assertSame('needs_review', DB::table('medical_credit_reservations')->sole()->state);
        $this->assertSame(400, (int) $f[2]->fresh()->remaining_cents);
        Http::assertNothingSent();
    }

    #[Test]
    public function uncoordinated_legacy_writers_block_before_reservation_and_legacy_cannot_spend_hold(): void
    {
        $f = $this->fixture();
        config()->set('akubica_connectors.financial.legacy_writers_coordinated', false);
        $this->rejects('LEGACY_CREDIT_WRITERS_NOT_COORDINATED', fn () => $this->begin($f));
        $this->assertSame(0, DB::table('medical_credit_reservations')->count());
        config()->set('akubica_connectors.financial.legacy_writers_coordinated', true);
        $this->begin($f);
        $purchase = new \App\Models\LaboratoryPurchase(['total_cents' => 1000]);
        try {
            app(\App\Services\CouponApplicationService::class)->applyForLaboratoryPurchase($f[0]['user'], $purchase, (int) $f[2]->id);
            $this->fail('Legacy consumed reserved funds');
        } catch (\App\Exceptions\CouponApplicationException) {
            $this->assertSame(400, (int) $f[2]->fresh()->remaining_cents);
        }
    }

    #[Test]
    public function confirmed_payment_can_recover_after_reservation_deadline_without_second_charge(): void
    {
        $f = $this->fixture();
        $p = $this->begin($f);
        $this->paypal($p['purchase_ref']);
        $svc = app(MedicalPurchaseSaga::class);
        $svc->start($f[1], $p['purchase_ref']);
        $this->travel(3)->minutes();
        $r = $svc->reconcile($f[1], $p['purchase_ref']);
        $this->assertSame('fulfilled', $r['state']);
        $this->assertSame(1, MedicalAttentionSubscription::count());
        $this->assertSame(1000, (int) MedicalAttentionSubscription::sole()->price_cents);
    }

    #[Test]
    public function refund_confirmation_precedes_compensation_and_repeated_reverse_has_one_effect(): void
    {
        $f = $this->fixture();
        $p = $this->begin($f);
        $this->paypal($p['purchase_ref']);
        $m = app(PayPalService::class);
        $m->shouldReceive('refund')->once()->with('synthetic-capture', 6, 'MXN')->andReturn(['id' => 'synthetic-refund', 'status' => 'COMPLETED', 'amount' => ['value' => '6.00', 'currency_code' => 'MXN']]);
        $svc = app(MedicalPurchaseSaga::class);
        $svc->start($f[1], $p['purchase_ref']);
        $svc->reconcile($f[1], $p['purchase_ref']);
        $r = $svc->reverse($f[1], $p['purchase_ref']);
        $this->assertSame('reversed', $r['state']);
        $this->assertSame(400, (int) $f[2]->fresh()->remaining_cents);
        $this->assertSame($r, $svc->reverse($f[1], $p['purchase_ref']));
        $this->assertSame(1, DB::table('opaque_benefit_movements')->where('kind', 'reversal')->count());
    }

    #[Test]
    public function uncertain_refund_never_recredits_or_recaptures_on_reconcile(): void
    {
        $f = $this->fixture();
        $p = $this->begin($f);
        $this->paypal($p['purchase_ref']);
        $m = app(PayPalService::class);
        $m->shouldReceive('refund')->once()->andReturn(['status' => 'PENDING']);
        $svc = app(MedicalPurchaseSaga::class);
        $svc->start($f[1], $p['purchase_ref']);
        $svc->reconcile($f[1], $p['purchase_ref']);
        $this->assertSame('needs_review', $svc->reverse($f[1], $p['purchase_ref'])['state']);
        $this->assertSame('needs_review', $svc->reconcile($f[1], $p['purchase_ref'])['state']);
        $this->assertSame(0, (int) $f[2]->fresh()->remaining_cents);
        $this->assertSame(0, DB::table('opaque_benefit_movements')->where('kind', 'reversal')->count());
    }

    #[Test]
    public function coupon_is_settled_as_discount_and_reversal_restores_eligibility_not_credit_money(): void
    {
        $f = $this->fixture(400, 'coupon');
        $p = $this->begin($f);
        $this->assertSame(400, $p['discount_cents']);
        $this->assertSame(0, $p['credit_cents']);
        $this->paypal($p['purchase_ref']);
        $m = app(PayPalService::class);
        $m->shouldReceive('refund')->once()->andReturn(['id' => 'synthetic-discount-refund', 'status' => 'COMPLETED', 'amount' => ['value' => '6.00', 'currency_code' => 'MXN']]);
        $svc = app(MedicalPurchaseSaga::class);
        $svc->start($f[1], $p['purchase_ref']);
        $this->assertSame('fulfilled', $svc->reconcile($f[1], $p['purchase_ref'])['state']);
        $this->assertSame(0, (int) $f[2]->fresh()->remaining_cents);
        $movement = DB::table('opaque_benefit_movements')->where('kind', 'debit')->sole();
        $this->assertSame('coupon', $movement->benefit_kind);
        $this->assertNull($movement->credit_transaction_id);
        $svc->reverse($f[1], $p['purchase_ref']);
        $this->assertSame(400, (int) $f[2]->fresh()->remaining_cents);
        $this->assertNull(CouponUser::where('coupon_id', $f[2]->id)->sole()->used_at);
    }

    #[Test]
    public function fulfillment_storage_failure_recovers_after_committed_debit_without_recharge(): void
    {
        $f = $this->fixture();
        $p = $this->begin($f);
        $this->paypal($p['purchase_ref']);
        $svc = app(MedicalPurchaseSaga::class);
        $svc->start($f[1], $p['purchase_ref']);
        $sql = DB::getDriverName() === 'mysql' ? "CREATE TRIGGER synthetic_member_failure BEFORE INSERT ON medical_attention_subscriptions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic failure'" : "CREATE TRIGGER synthetic_member_failure BEFORE INSERT ON medical_attention_subscriptions BEGIN SELECT RAISE(ABORT,'synthetic failure'); END";
        DB::unprepared($sql);
        try {
            try {
                $svc->reconcile($f[1], $p['purchase_ref']);
                $this->fail('Expected storage failure');
            } catch (\Illuminate\Database\QueryException) {
                $this->assertSame(1, DB::table('opaque_benefit_movements')->where('kind', 'debit')->count());
                $this->assertSame(0, MedicalAttentionSubscription::count());
            }
        } finally {
            DB::unprepared('DROP TRIGGER synthetic_member_failure');
        }
        $this->assertSame('fulfilled', $svc->reconcile($f[1], $p['purchase_ref'])['state']);
        $this->assertSame(1, DB::table('opaque_benefit_movements')->where('kind', 'debit')->count());
        $this->assertSame(1, MedicalAttentionSubscription::count());
        $this->assertSame(1000, (int) MedicalAttentionSubscription::sole()->price_cents);
    }

    private function race(array $f, string $mode, ?string $purchase = null, bool $sameKey = true): array
    {
        $prefix = getenv('APP_BASE_PATH').'/financial-race-'.bin2hex(random_bytes(6));
        $go = $prefix.'.go';
        $jobs = [];
        $env = getenv();
        $env['FAMEDIC_V2_PREPARE_SCHEMA'] = '0';
        try {
            foreach ([0, 1] as $i) {
                $ready = $prefix.'.'.$i.'.ready';
                $proc = proc_open([PHP_BINARY, '-d', 'allow_url_fopen=0', __DIR__.'/financial-worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
                $this->assertIsResource($proc);
                fwrite($pipes[0], json_encode(['ready' => $ready, 'go' => $go, 'mode' => $mode, 'actor' => [$f[1]->userId, $f[1]->consumerId, $f[1]->delegationId, $f[1]->channelId], 'cart_ref' => $f[3]['cart_ref'], 'application_ref' => $f[4]['application_ref'], 'purchase_ref' => $purchase, 'key' => $sameKey ? 'synthetic-concurrent-intent-01' : 'synthetic-concurrent-intent-0'.$i]));
                fclose($pipes[0]);
                $jobs[] = [$proc, $pipes, $ready];
            }
            $deadline = microtime(true) + 20;
            while (! is_file($jobs[0][2]) || ! is_file($jobs[1][2])) {
                if (microtime(true) > $deadline) {
                    throw new \RuntimeException('Synthetic workers not ready');
                }usleep(20000);
            }touch($go);
            $results = [];
            foreach ($jobs as [$proc,$pipes,$ready]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($proc), 'Synthetic financial worker failed');
                $results[] = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            if (! is_file($go)) {
                touch($go);
            }foreach (glob($prefix.'*') as $p) {
                unlink($p);
            }
        }
    }

    #[Test,\PHPUnit\Framework\Attributes\Group('mysql')]
    public function mysql_two_simultaneous_identical_intents_make_one_reservation_and_transaction(): void
    {
        $f = $this->fixture();
        $results = $this->race($f, 'begin');
        $this->assertSame([200, 200], array_column($results, 'status'), json_encode($results));
        $this->assertSame(1, DB::table('medical_purchase_intents')->count());
        $this->assertSame(1, DB::table('medical_credit_reservations')->count());
        $this->assertSame(1, Transaction::count());
    }

    #[Test,\PHPUnit\Framework\Attributes\Group('mysql')]
    public function mysql_distinct_intents_cannot_double_reserve_one_application(): void
    {
        $f = $this->fixture();
        $results = $this->race($f, 'begin', sameKey: false);
        $codes = array_column($results, 'status');
        sort($codes);
        $this->assertSame([200, 409], $codes, json_encode($results));
        $this->assertSame(1, DB::table('medical_credit_reservations')->count());
        $this->assertSame(400, (int) $f[2]->fresh()->remaining_cents);
    }

    #[Test,\PHPUnit\Framework\Attributes\Group('mysql')]
    public function mysql_two_full_credit_confirmations_have_one_debit_and_membership(): void
    {
        $f = $this->fixture(1500);
        $p = $this->begin($f);
        $results = $this->race($f, 'start', $p['purchase_ref']);
        $this->assertSame([200, 200], array_column($results, 'status'), json_encode($results));
        $this->assertSame(1, DB::table('opaque_benefit_movements')->where('kind', 'debit')->count());
        $this->assertSame(1, MedicalAttentionSubscription::count());
        $this->assertSame(1000, (int) MedicalAttentionSubscription::sole()->price_cents);
        $this->assertSame(500, (int) $f[2]->fresh()->remaining_cents);
        $this->assertSame(1, DB::table('medical_settlement_outbox')->count());
    }

    #[Test]
    public function expired_or_changed_reservation_never_starts_a_provider_charge(): void
    {
        $f = $this->fixture();
        $p = $this->begin($f);
        $this->app->instance(PayPalService::class, \Mockery::mock(PayPalService::class));
        $this->travel(3)->minutes();
        $this->rejects('RESERVATION_NOT_ACTIVE', fn () => app(MedicalPurchaseSaga::class)->start($f[1], $p['purchase_ref']));
        $this->travelBack();
        \App\Models\MedicalServiceCartItem::query()->update(['subtotal_cents' => 1200]);
        $this->rejects('PURCHASE_CONTEXT_CHANGED', fn () => app(MedicalPurchaseSaga::class)->start($f[1], $p['purchase_ref']));
        $this->assertSame(0, DB::table('opaque_benefit_movements')->count());
        $this->assertSame(0, MedicalAttentionSubscription::count());
        Http::assertNothingSent();
    }

    #[Test]
    public function purchase_authority_does_not_implicitly_authorize_refunds(): void
    {
        $f = $this->fixture(1500);
        $p = $this->begin($f);
        $svc = app(MedicalPurchaseSaga::class);
        $svc->start($f[1], $p['purchase_ref']);
        Gate::define('reverseMedicalBenefits', fn () => false);
        try {
            $svc->reverse($f[1], $p['purchase_ref']);
            $this->fail('Purchase authority authorized refund');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertSame(500, (int) $f[2]->fresh()->remaining_cents);
        }
        $this->assertSame(0, DB::table('opaque_benefit_movements')->where('kind', 'reversal')->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function efevoo_full_purchase_uses_real_charge_action_then_ledger_and_membership(): void
    {
        $f = $this->fixture();
        config()->set('akubica_connectors.providers.efevoopay.certified', true);
        config()->set('efevoopay.verify_ssl', true);
        $p = $this->begin($f, 'efevoopay');
        $token = \App\Models\EfevooToken::factory()->make(['card_token' => 'synthetic-card-token-not-real', 'card_expiration' => '1230', 'environment' => 'test', 'expires_at' => now()->addYear()]);
        $token->offsetUnset('client_id');
        $f[0]['user']->customer->efevooTokens()->save($token);
        $gateway = \Mockery::mock(\App\Contracts\EfevooPayGateway::class);
        $gateway->shouldReceive('chargeCard')->once()->with(\Mockery::on(fn ($x) => $x['amount'] === 6 && $x['reference'] === $p['purchase_ref']))->andReturn(['success' => true, 'transaction_id' => '12345', 'raw' => ['data' => ['amount' => '6.00', 'currency' => 'MXN', 'reference' => $p['purchase_ref']]]]);
        $this->app->instance(\App\Contracts\EfevooPayGateway::class, $gateway);
        $r = app(MedicalPurchaseSaga::class)->start($f[1], $p['purchase_ref'], (string) $token->id);
        $this->assertSame('fulfilled', $r['state']);
        $this->assertSame(0, (int) $f[2]->fresh()->remaining_cents);
        $this->assertSame(1, MedicalAttentionSubscription::count());
        $tx = Transaction::where('reference_id', $p['purchase_ref'])->sole();
        $this->assertSame('medical_benefit_settlement', $tx->details['purpose']);
        $this->assertSame(1, DB::table('medical_payment_verifications')->count());
        $service = \Mockery::mock(\App\Services\EfevooPayService::class);
        $service->shouldReceive('refundTransaction')->once()->with(12345)->andReturn(['success' => true, 'data' => ['codigo' => '00']]);
        $service->shouldReceive('searchTransactions')->once()->with(['transaction_id' => 12345])->andReturn(['success' => true, 'data' => ['data' => [['id' => '12345', 'amount' => '6.00', 'currency' => 'MXN', 'reference' => $p['purchase_ref'], 'status' => 'REFUNDED', 'refund_id' => 'synthetic-efevoo-refund']]]]);
        $this->app->instance(\App\Services\EfevooPayService::class, $service);
        $svc = app(MedicalPurchaseSaga::class);
        $this->assertSame('needs_review', $svc->reverse($f[1], $p['purchase_ref'])['state']);
        $this->assertSame('reversed', $svc->reconcile($f[1], $p['purchase_ref'])['state']);
        $this->assertSame(400, (int) $f[2]->fresh()->remaining_cents);
        Http::assertNothingSent();
    }

    #[Test]
    public function odessa_full_purchase_uses_real_charge_action_then_ledger_and_membership(): void
    {
        $f = $this->fixture();
        config()->set('akubica_connectors.providers.odessa.certified', true);
        $account = \App\Models\OdessaAfiliateAccount::factory()->create();
        $customer = $f[0]['user']->customer;
        $customer->customerable()->associate($account);
        $customer->save();
        $p = $this->begin($f, 'odessa');
        $get = \Mockery::mock(\App\Actions\Odessa\GetOdessaPrivateTokenAction::class);
        $get->shouldReceive('__invoke')->once()->andReturn('synthetic-only-token');
        $this->app->instance(\App\Actions\Odessa\GetOdessaPrivateTokenAction::class, $get);
        $balance = \Mockery::mock(\App\Actions\Odessa\CheckBalanceAction::class);
        $balance->shouldReceive('__invoke')->once()->with('synthetic-only-token', 600)->andReturn(true);
        $this->app->instance(\App\Actions\Odessa\CheckBalanceAction::class, $balance);
        \Illuminate\Support\Facades\Http::swap(new \Tests\Support\Http\OfflineHttpFactory($this->app['events']));
        Http::preventStrayRequests();
        config()->set('services.odessa.url', 'https://odessa.synthetic.invalid/');
        $tx = Transaction::where('reference_id', $p['purchase_ref'])->sole();
        Http::fake(['odessa.synthetic.invalid/*' => Http::response(['response' => ['errorCode' => 0, 'amount' => '6.00', 'currency' => 'MXN', 'transaction' => (string) $tx->id, 'transactionOds' => 'synthetic-odessa-settlement']], 200)]);
        $r = app(MedicalPurchaseSaga::class)->start($f[1], $p['purchase_ref'], 'odessa');
        $this->assertSame('fulfilled', $r['state']);
        $this->assertSame(0, (int) $f[2]->fresh()->remaining_cents);
        $this->assertSame(1, MedicalAttentionSubscription::count());
        Http::assertSentCount(1);
        $r = app(MedicalPurchaseSaga::class)->reverse($f[1], $p['purchase_ref']);
        $this->assertSame('needs_review', $r['state']);
        $this->assertSame(0, DB::table('opaque_benefit_movements')->where('kind', 'reversal')->count());
    }

    #[Test]
    public function durable_outbox_job_delivers_once_and_defaults_are_closed(): void
    {
        $f = $this->fixture(1500);
        $p = $this->begin($f);
        app(MedicalPurchaseSaga::class)->start($f[1], $p['purchase_ref']);
        $id = DB::table('medical_settlement_outbox')->sole()->id;
        $operator = $f[0]['issuer'];
        $service = app(\App\Services\MedicalCheckout\MedicalSettlementOutbox::class);
        $this->rejects('FULFILLMENT_DELIVERY_PENDING_CERTIFICATION', fn () => $service->deliver($operator, $id));
        config()->set('akubica_connectors.outbox', ['enabled' => true, 'certified' => true]);
        Gate::define('dispatchMedicalSettlementOutbox', fn (User $u) => (int) $u->id === (int) $operator->id);
        $sync = \Mockery::mock(\App\Actions\MedicalAttention\SyncSubscriptionToMurguiaAction::class);
        $sync->shouldReceive('__invoke')->once()->andReturn(true);
        $this->app->instance(\App\Actions\MedicalAttention\SyncSubscriptionToMurguiaAction::class, $sync);
        (new \App\Jobs\ProcessMedicalSettlementOutbox((int) $operator->id, (int) $id))->handle($service);
        $this->assertSame('delivered', $service->deliver($operator, $id));
        $this->assertSame('delivered', DB::table('medical_settlement_outbox')->find($id)->state);
        Http::assertNothingSent();
    }

    #[Test]
    public function unknown_outbox_ack_is_not_retried_without_separate_operator_evidence(): void
    {
        $f = $this->fixture(1500);
        $p = $this->begin($f);
        app(MedicalPurchaseSaga::class)->start($f[1], $p['purchase_ref']);
        $id = DB::table('medical_settlement_outbox')->sole()->id;
        $operator = $f[0]['issuer'];
        config()->set('akubica_connectors.outbox', ['enabled' => true, 'certified' => true]);
        Gate::define('dispatchMedicalSettlementOutbox', fn () => true);
        $sync = \Mockery::mock(\App\Actions\MedicalAttention\SyncSubscriptionToMurguiaAction::class);
        $sync->shouldReceive('__invoke')->once()->andReturn(false);
        $this->app->instance(\App\Actions\MedicalAttention\SyncSubscriptionToMurguiaAction::class, $sync);
        $service = app(\App\Services\MedicalCheckout\MedicalSettlementOutbox::class);
        $this->assertSame('needs_review', $service->deliver($operator, $id));
        $this->rejects('FULFILLMENT_DELIVERY_UNCERTAIN', fn () => $service->deliver($operator, $id));
        try {
            $service->certifyOutcome($operator, $id, true, 'synthetic-delivery-proof');
            $this->fail('Unapproved reconciliation');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertTrue(true);
        }
        Gate::define('reconcileMedicalSettlementOutbox', fn () => true);
        $service->certifyOutcome($operator, $id, true, 'synthetic-delivery-proof');
        $this->assertSame('delivered', $service->deliver($operator, $id));
        Http::assertNothingSent();
    }

    #[Test]
    public function reversal_supersedes_pending_activation_and_delivers_inactive_without_registration(): void
    {
        $f = $this->fixture(1500);
        $p = $this->begin($f);
        $saga = app(MedicalPurchaseSaga::class);
        $saga->start($f[1], $p['purchase_ref']);
        $saga->reverse($f[1], $p['purchase_ref']);
        $operator = $f[0]['issuer'];
        config()->set('akubica_connectors.outbox', ['enabled' => true, 'certified' => true]);
        Gate::define('dispatchMedicalSettlementOutbox', fn () => true);
        $update = \Mockery::mock(\App\Actions\MedicalAttention\UpdateStatusAction::class);
        $update->shouldReceive('__invoke')->once()->with(\Mockery::on(fn ($c) => (int) $c->id === (int) $f[0]['user']->customer->id), 'inactivo')->andReturn(new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode(['success' => true]))));
        // The adapter consumes Laravel Response, matching the real action's return contract.
        $this->app->instance(\App\Actions\MedicalAttention\UpdateStatusAction::class, $update);
        $service = app(\App\Services\MedicalCheckout\MedicalSettlementOutbox::class);
        $this->assertSame('superseded', $service->deliver($operator, DB::table('medical_settlement_outbox')->where('kind', 'activate')->sole()->id));
        $this->assertSame('delivered', $service->deliver($operator, DB::table('medical_settlement_outbox')->where('kind', 'reverse')->sole()->id));
    }

    #[Test]
    public function cancellation_before_dispatch_releases_once_and_prevents_subsequent_charge(): void
    {
        $f = $this->fixture();
        $p = $this->begin($f);
        $saga = app(MedicalPurchaseSaga::class);
        $r = $saga->cancelUncharged($f[1], $p['purchase_ref']);
        $this->assertSame('cancelled', $r['state']);
        $this->assertSame($r, $saga->cancelUncharged($f[1], $p['purchase_ref']));
        $this->assertSame('released', DB::table('medical_credit_reservations')->sole()->state);
        $this->assertSame('cancelled', $saga->start($f[1], $p['purchase_ref'])['state']);
        $this->assertSame(400, (int) $f[2]->fresh()->remaining_cents);
        $this->assertSame(0, MedicalAttentionSubscription::count());
        Http::assertNothingSent();
    }

    #[Test]
    public function revoked_actor_cannot_fulfill_but_authorized_operator_can_refund_without_debit(): void
    {
        $f = $this->fixture();
        $p = $this->begin($f);
        $this->paypal($p['purchase_ref']);
        $paypal = app(PayPalService::class);
        $paypal->shouldReceive('refund')->once()->andReturn(['id' => 'synthetic-admin-refund', 'status' => 'COMPLETED', 'amount' => ['value' => '6.00', 'currency_code' => 'MXN']]);
        $saga = app(MedicalPurchaseSaga::class);
        $saga->start($f[1], $p['purchase_ref']);
        $lifecycle = app(\App\Services\Api\V1\Auth\ConsumerLifecycle::class);
        $lifecycle->transition($f[0]['issuer'], $f[0]['consumer'], false, 'synthetic-recovery-revocation');
        $lifecycle->transition($f[0]['issuer'], $f[0]['channel'], false, 'synthetic-channel-revocation');
        foreach ([$f[0]['consumer'], $f[0]['channel']] as $record) {
            $this->assertNotNull($record->fresh()->revoked_at);
            try {
                $lifecycle->transition($f[0]['issuer'], $record, true, 'synthetic-no-reactivation');
                $this->fail('Revocation must be irreversible');
            } catch (\DomainException) {
                $this->assertFalse($record->fresh()->enabled);
            }
        }
        $this->rejects('CONSUMER_NOT_AUTHORIZED', fn () => $saga->reconcile($f[1], $p['purchase_ref']));
        $operator = $f[0]['issuer'];
        try {
            $saga->recoverForOperator($operator, $p['purchase_ref'], 'refund', 'synthetic-recovery-approval');
            $this->fail('Missing operator authority');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->assertTrue(true);
        }
        Gate::define('reconcileMedicalBenefits', fn (User $u) => (int) $u->id === (int) $operator->id);
        Gate::define('reverseMedicalBenefits', fn (User $u) => (int) $u->id === (int) $operator->id);
        $r = $saga->recoverForOperator($operator, $p['purchase_ref'], 'refund', 'synthetic-recovery-approval');
        $this->assertSame('reversed', $r['state']);
        $this->assertSame(400, (int) $f[2]->fresh()->remaining_cents);
        $this->assertSame(0, DB::table('opaque_benefit_movements')->count());
        $this->assertSame(0, MedicalAttentionSubscription::count());
        $this->assertSame('reversed', DB::table('medical_recovery_audit')->sole()->result_state);
        Http::assertNothingSent();
    }

    #[Test]
    public function lost_order_id_requires_verified_operator_observation_before_binding_and_recovery(): void
    {
        $f = $this->fixture();
        $p = $this->begin($f);
        $paypal = \Mockery::mock(PayPalService::class);
        $paypal->shouldReceive('createOrder')->once()->andThrow(new \RuntimeException('Synthetic lost acknowledgement'));
        $paypal->shouldReceive('getOrder')->andReturn($this->order($p['purchase_ref']));
        $this->app->instance(PayPalService::class, $paypal);
        $saga = app(MedicalPurchaseSaga::class);
        $this->rejects('PAYMENT_OUTCOME_UNCERTAIN', fn () => $saga->start($f[1], $p['purchase_ref']));
        $operator = $f[0]['issuer'];
        Gate::define('reconcileMedicalBenefits', fn (User $u) => (int) $u->id === (int) $operator->id);
        $this->rejects('PURCHASE_NOT_VERIFIED', fn () => $saga->bindCapturedPayPalOrderForOperator($operator, $p['purchase_ref'], 'unrelated-order', 'synthetic-lost-order-proof'));
        $this->assertNull(Transaction::where('reference_id', $p['purchase_ref'])->sole()->provider_order_id);
        $saga->bindCapturedPayPalOrderForOperator($operator, $p['purchase_ref'], 'synthetic-order', 'synthetic-lost-order-proof');
        $this->assertSame(400, (int) $f[2]->fresh()->remaining_cents);
        $this->assertSame(0, MedicalAttentionSubscription::count());
        $this->assertSame('fulfilled', $saga->reconcile($f[1], $p['purchase_ref'])['state']);
        $this->assertSame(1, MedicalAttentionSubscription::count());
        Http::assertNothingSent();
    }
}
