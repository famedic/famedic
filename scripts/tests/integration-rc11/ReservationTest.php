<?php

namespace Famedic\Integration;

use App\Contracts\Api\V1\Auth\BackendCredentialSource;
use App\Domain\Akubica\Benefits\BenefitActor;
use App\Domain\Akubica\Benefits\BenefitFailure;
use App\Models\Coupon;
use App\Models\CouponUser;
use App\Models\MedicalAttentionSubscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Api\V1\Benefits\BenefitApprovals;
use App\Services\Api\V1\Benefits\MedicalCreditReservations;
use App\Services\Api\V1\Benefits\MedicalPaymentAuthority;
use App\Services\Api\V1\Benefits\ReservationReleaseAuthority;
use App\Services\Api\V1\Benefits\UnavailableReservationReleaseAuthority;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Api\V1\Identity\LocalMachineCredentialSource;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture as Fixture;
use Tests\TestCase;

final class ReserveApprovals implements BenefitApprovals
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
        return 'synthetic-reserve-v1';
    }
}
final class ReservationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Queue::fake();
        Notification::fake();
        Http::fake();
        config()->set('famedic.medical_attention_subscription_price_cents', 1000);
        $this->app->instance(BenefitApprovals::class, new ReserveApprovals);
        $this->app->bind(BackendCredentialSource::class, LocalMachineCredentialSource::class);
        $this->app->instance(MedicalPaymentAuthority::class, new class implements MedicalPaymentAuthority
        {
            public function confirmed(Transaction $p, int $u, int $amount): bool
            {
                return true;
            }

            public function reversed(Transaction $p, int $u): bool
            {
                return true;
            }
        });
        $this->app->instance(ReservationReleaseAuthority::class, new class implements ReservationReleaseAuthority
        {
            public function definitivelyUnpaid(Transaction $p, int $u): bool
            {
                return true;
            }
        });
    }

    private function fixture(?Coupon $shared = null, int $credit = 1500): array
    {
        $a = Fixture::issue(User::factory()->withRegularCustomer()->create(), 'leo', ['benefits:read', 'benefits:apply', 'benefits:remove']);
        app(\App\Services\Api\V1\MedicalServiceCartService::class)->addBasicPlan($a['user']->customer);
        $coupon = $shared ?? Coupon::factory()->create(['amount_cents' => $credit, 'remaining_cents' => $credit]);
        CouponUser::create(['coupon_id' => $coupon->id, 'user_id' => $a['user']->id]);
        $headers = ['Authorization' => 'Bearer '.$a['token']->plainTextToken, 'X-Synthetic-Machine-Credential' => $a['machine']->plainTextToken];
        $this->flushHeaders();
        $s = $this->getJson('/api/v1/cart/benefits?service_line=medical_services', $headers)->assertOk()->json('data');
        $this->flushHeaders();
        $this->post('/logout');
        $this->post('/login', ['email' => $a['user']->email, 'password' => 'password'])->assertRedirect();
        $this->post('/confirm-password', ['password' => 'password'])->assertRedirect();
        $cnf = $this->postJson('/benefits/consent/confirm', ['cart_ref' => $s['cart_ref'], 'cart_version' => $s['cart_version'], 'target_ref' => $s['benefits'][0]['benefit_ref'], 'operation' => 'apply', 'text_version' => 'synthetic-reserve-v1'])->assertOk()->json('data.confirmation_ref');
        $this->flushHeaders();
        $ap = $this->postJson('/api/v1/cart/benefits/applications', ['service_line' => 'medical_services', 'cart_ref' => $s['cart_ref'], 'cart_version' => $s['cart_version'], 'benefit_ref' => $s['benefits'][0]['benefit_ref'], 'confirmation_ref' => $cnf], $headers + ['Idempotency-Key' => 'synthetic-reserve-apply-0001'])->assertCreated()->json('data');
        $actor = new BenefitActor((int) $a['user']->id, (string) $a['consumer']->id, (string) $a['delegation']->id, (string) $a['channel']->id);

        return [$a, $actor, $coupon, $s, $ap];
    }

    private function hold(array $f, string $key = 'synthetic-reserve-order-0001'): array
    {
        return app(MedicalCreditReservations::class)->reserve($f[1], $f[3]['cart_ref'], $f[4]['application_ref'], $key);
    }

    private function pending(array $f): Transaction
    {
        $tx = Transaction::create(['transaction_amount_cents' => $f[4]['totals']['total_cents'], 'payment_status' => 'pending', 'payment_method' => 'paypal', 'gateway' => 'paypal', 'reference_id' => 'synthetic-reservation-only', 'details' => ['customer_id' => $f[0]['user']->customer->id]]);
        $member = MedicalAttentionSubscription::create(['customer_id' => $f[0]['user']->customer->id, 'price_cents' => 1000, 'type' => \App\Enums\MedicalSubscriptionType::REGULAR, 'start_date' => now(), 'end_date' => now()->addMonth()]);
        $member->transactions()->attach($tx->id);

        return $tx;
    }

    private function rejects(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected controlled failure');
        } catch (BenefitFailure $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    #[Test]
    public function reservation_does_not_debit_and_replays_without_second_hold(): void
    {
        $f = $this->fixture();
        $h = $this->hold($f);
        $again = $this->hold($f);
        $this->assertTrue($again['replayed']);
        $this->assertSame($h['reservation_ref'], $again['reservation_ref']);
        $this->assertSame(1500, (int) $f[2]->fresh()->remaining_cents);
        $this->assertSame(1, DB::table('medical_credit_reservations')->count());
        $this->assertSame(0, DB::table('opaque_benefit_movements')->count());
    }

    #[Test]
    public function unknown_payment_and_expiry_keep_funds_held_until_authoritative_outcome(): void
    {
        $f = $this->fixture();
        $h = $this->hold($f);
        $tx = $this->pending($f);
        $svc = app(MedicalCreditReservations::class);
        $svc->bindPayment($f[1], $h['reservation_ref'], $tx->id);
        $this->rejects('PURCHASE_NOT_VERIFIED', fn () => $svc->confirmAndDebit($f[1], $h['reservation_ref']));
        $this->rejects('PAYMENT_OUTCOME_UNCERTAIN', fn () => $svc->release($f[1], $h['reservation_ref']));
        $this->travel(121)->seconds();
        $this->assertSame('needs_review', $svc->markExpired($f[1], $h['reservation_ref'])['state']);
        $this->assertSame(1000, (int) DB::table('medical_credit_reservations')->where('state', 'needs_review')->sum('amount_cents'));
        $this->rejects('RESERVATION_REQUIRES_RECONCILIATION', fn () => $svc->confirmAndDebit($f[1], $h['reservation_ref']));
        $this->travelBack();
        $this->assertSame(1500, (int) $f[2]->fresh()->remaining_cents);
    }

    #[Test]
    public function confirmed_payment_debits_once_and_compensation_restores_once(): void
    {
        $f = $this->fixture();
        $h = $this->hold($f);
        $tx = $this->pending($f);
        $svc = app(MedicalCreditReservations::class);
        $svc->bindPayment($f[1], $h['reservation_ref'], $tx->id);
        $tx->update(['payment_status' => 'captured']);
        $this->assertSame('committed', $svc->confirmAndDebit($f[1], $h['reservation_ref'])['state']);
        $this->assertTrue($svc->confirmAndDebit($f[1], $h['reservation_ref'])['replayed']);
        $this->assertSame(500, (int) $f[2]->fresh()->remaining_cents);
        $tx->update(['payment_status' => 'refunded']);
        $this->assertSame('compensated', $svc->compensate($f[1], $h['reservation_ref'])['state']);
        $this->assertTrue($svc->compensate($f[1], $h['reservation_ref'])['replayed']);
        $this->assertSame(1500, (int) $f[2]->fresh()->remaining_cents);
        $this->assertSame(2, DB::table('opaque_benefit_movements')->count());
    }

    #[Test]
    public function default_financial_gate_blocks_reservation_and_unpaid_authority_is_required(): void
    {
        $f = $this->fixture();
        $this->app->instance(BenefitApprovals::class, new \App\Services\Api\V1\Benefits\PendingBenefitApprovals);
        $this->rejects('FINANCIAL_WRITES_PENDING_APPROVAL', fn () => $this->hold($f));
        $this->app->instance(BenefitApprovals::class, new ReserveApprovals);
        $h = $this->hold($f);
        $tx = $this->pending($f);
        $svc = app(MedicalCreditReservations::class);
        $svc->bindPayment($f[1], $h['reservation_ref'], $tx->id);
        $tx->update(['payment_status' => 'failed']);
        $this->app->instance(ReservationReleaseAuthority::class, new UnavailableReservationReleaseAuthority);
        $this->rejects('PAYMENT_OUTCOME_UNCERTAIN', fn () => app(MedicalCreditReservations::class)->release($f[1], $h['reservation_ref']));
        $this->assertSame('released', $svc->release($f[1], $h['reservation_ref'])['state']);
        $this->assertSame(1500, (int) $f[2]->fresh()->remaining_cents);
    }

    #[Test]
    public function another_consumers_purchase_cannot_use_reserved_credit(): void
    {
        $a = $this->fixture();
        $b = $this->fixture($a[2]);
        $this->hold($a);
        $this->rejects('INSUFFICIENT_UNRESERVED_CREDIT', fn () => $this->hold($b));
        $this->assertSame(1, DB::table('medical_credit_reservations')->count());
    }

    #[Test]
    public function ledger_failure_rolls_back_settlement_and_hold_can_recover(): void
    {
        $f = $this->fixture();
        $h = $this->hold($f);
        $tx = $this->pending($f);
        $svc = app(MedicalCreditReservations::class);
        $svc->bindPayment($f[1], $h['reservation_ref'], $tx->id);
        $tx->update(['payment_status' => 'captured']);
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER synthetic_reservation_failure BEFORE INSERT ON opaque_benefit_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic failure'");
        } else {
            DB::unprepared("CREATE TRIGGER synthetic_reservation_failure BEFORE INSERT ON opaque_benefit_movements BEGIN SELECT RAISE(ABORT,'synthetic failure'); END");
        }try {
            try {
                $svc->confirmAndDebit($f[1], $h['reservation_ref']);
                $this->fail('Expected DB failure');
            } catch (\Illuminate\Database\QueryException) {
                $this->assertSame(1500, (int) $f[2]->fresh()->remaining_cents);
                $this->assertSame(0, DB::table('opaque_benefit_settlements')->count());
                $this->assertNull(DB::table('medical_credit_reservations')->sole()->settlement_id);
            }
        } finally {
            DB::unprepared('DROP TRIGGER synthetic_reservation_failure');
        }$this->assertSame('committed', $svc->confirmAndDebit($f[1], $h['reservation_ref'])['state']);
    }

    #[Test]
    public function compensation_recovers_after_reversal_committed_before_reservation_update(): void
    {
        $f = $this->fixture();
        $h = $this->hold($f);
        $tx = $this->pending($f);
        $svc = app(MedicalCreditReservations::class);
        $svc->bindPayment($f[1], $h['reservation_ref'], $tx->id);
        $tx->update(['payment_status' => 'captured']);
        $svc->confirmAndDebit($f[1], $h['reservation_ref']);
        $tx->update(['payment_status' => 'refunded']);
        $row = DB::table('medical_credit_reservations')->sole();
        app(\App\Services\Api\V1\Benefits\MedicalCreditSettlementBridge::class)->reverse($f[1], \Illuminate\Support\Facades\Crypt::decryptString($row->purchase_ref_ciphertext));
        $this->assertSame('compensated', $svc->compensate($f[1], $h['reservation_ref'])['state']);
        $this->assertSame(2, DB::table('opaque_benefit_movements')->count());
        $this->assertSame(1500, (int) $f[2]->fresh()->remaining_cents);
    }

    #[Test,\PHPUnit\Framework\Attributes\Group('mysql')]
    public function mysql_concurrent_shared_credit_reservations_accept_only_one(): void
    {
        $a = $this->fixture();
        $b = $this->fixture($a[2]);
        $rt = getenv('APP_BASE_PATH');
        $prefix = $rt.'/reserve-race-'.bin2hex(random_bytes(5));
        $go = $prefix.'.go';
        $jobs = [];
        $env = getenv();
        $env['FAMEDIC_V2_PREPARE_SCHEMA'] = '0';
        try {
            foreach ([$a, $b] as $i => $fixture) {
                $actor = $fixture[1];
                $ready = $prefix.'.'.$i.'.ready';
                $proc = proc_open([PHP_BINARY, '-d', 'allow_url_fopen=0', dirname(__DIR__).'/benefits-closure/http-worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
                $this->assertIsResource($proc);
                fwrite($pipes[0], json_encode(['mode' => 'reserve', 'actor' => [$actor->userId, $actor->consumerId, $actor->delegationId, $actor->channelId], 'cart_ref' => $fixture[3]['cart_ref'], 'application_ref' => $fixture[4]['application_ref'], 'key' => 'synthetic-parallel-reserve-000'.$i, 'ready' => $ready, 'go' => $go]));
                fclose($pipes[0]);
                $jobs[] = [$proc, $pipes, $ready];
            }
            $deadline = microtime(true) + 15;
            while (! is_file($jobs[0][2]) || ! is_file($jobs[1][2])) {
                if (microtime(true) > $deadline) {
                    throw new \RuntimeException('Reservation workers failed to reach barrier');
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
            $this->assertSame(1, DB::table('medical_credit_reservations')->count());
            $this->assertSame(1500,(int) $a[2]->fresh()->remaining_cents);
            $this->assertSame(0,DB::table('opaque_benefit_movements')->count());
        } finally {
            if (! is_file($go)) {
                touch($go);
            }foreach (glob($prefix.'*') as $file) {
                unlink($file);
            }
        }
    }
}
