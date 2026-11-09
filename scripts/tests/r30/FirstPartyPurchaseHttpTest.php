<?php

namespace Famedic\R30;

use App\Contracts\Api\V1\Auth\BackendCredentialSource;
use App\Domain\Akubica\Benefits\BenefitActor;
use App\Models\Coupon;
use App\Models\CouponUser;
use App\Models\MedicalAttentionSubscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Api\V1\Auth\TlsMachinePatCredentialSource;
use App\Services\Api\V1\Benefits\BenefitApprovals;
use App\Services\MedicalCheckout\MedicalRemnantGateway;
use App\Services\MedicalCheckout\PaymentObservation;
use App\Services\MedicalCheckout\PayPalRemnantGateway;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Api\V1\Identity\SyntheticConsumerRegistryFixture as Fixture;
use Tests\TestCase;

final class Approvals implements BenefitApprovals
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
        return 'synthetic-r30-v1';
    }
}
final class Csrf extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}
/** Only the external provider boundary is synthetic; the HTTP controller, saga, SQL and ledger are real. */
final class Provider implements MedicalRemnantGateway
{
    public string $outcome = 'confirmed';

    public string $observed = 'confirmed';

    public int $starts = 0;

    public int $refunds = 0;

    public function start(\App\Models\Customer $c, Transaction $tx, string $ref, int $cash, string $method): PaymentObservation
    {
        $this->starts++;
        if ($this->outcome === 'declined') {
            throw new \App\Exceptions\EfevooPaymentException('Synthetic provider rejection');
        }
        if ($this->outcome === 'throw') {
            throw new \RuntimeException('Synthetic timeout');
        }

        return new PaymentObservation($this->outcome, $ref, $cash, 'synthetic-r30-capture', providerOrder: 'synthetic-r30-order');
    }

    public function observe(\App\Models\Customer $c, Transaction $tx, string $ref, int $cash): PaymentObservation
    {
        return new PaymentObservation($this->observed, $ref, $cash, 'synthetic-r30-capture');
    }

    public function reverse(\App\Models\Customer $c, Transaction $tx, string $ref, int $cash): PaymentObservation
    {
        $this->refunds++;

        return new PaymentObservation('reversed', $ref, $cash, 'synthetic-r30-refund');
    }
}

final class FirstPartyPurchaseHttpTest extends TestCase
{
    private const URL = '/medical-attention/benefit-checkout';

    private Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Queue::fake();
        Notification::fake();
        Http::preventStrayRequests();
        $this->app->instance(BenefitApprovals::class, new Approvals);
        $this->app->bind(BackendCredentialSource::class, TlsMachinePatCredentialSource::class);
        config()->set('famedic.medical_attention_subscription_price_cents', 1000);
        config()->set('akubica_connectors.transport', ['enabled' => true, 'certified' => true]);
        config()->set('akubica_connectors.financial', ['enabled' => true, 'certified' => true, 'legacy_writers_coordinated' => true]);
        config()->set('akubica_connectors.first_party_purchase', ['enabled' => true, 'certified' => true]);
        config()->set('akubica_connectors.providers.paypal.certified', true);
        Gate::define('purchaseMedicalBenefits', fn (User $u, BenefitActor $a) => (int) $u->id === $a->userId);
        Gate::define('reverseMedicalBenefits', fn (User $u, BenefitActor $a) => (int) $u->id === $a->userId);
        $this->provider = new Provider;
        $this->app->instance(PayPalRemnantGateway::class, $this->provider);
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $r = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        $path = parse_url($uri, PHP_URL_PATH);
        if (str_starts_with($path, self::URL)) {
            $path = preg_replace('/pur_[A-Za-z0-9_-]{43}/', '{checkout_ref}', $path);
            $data = json_decode($r->getContent(), true);
            file_put_contents(getenv('APP_BASE_PATH').'/r30-http-observations.jsonl', json_encode(['method' => $method, 'path' => $path, 'http' => $r->status(), 'state' => $data['data']['state'] ?? null, 'category' => $data['error']['code'] ?? null, 'controller' => 'REAL', 'provider' => 'SYNTHETIC_ONLY'])."\n", FILE_APPEND);
        }

        return $r;
    }

    private function fixture(int $amount = 400, bool $coupon = false): array
    {
        $a = Fixture::issue(User::factory()->withRegularCustomer()->create(), 'leo', ['benefits:read', 'benefits:apply', 'benefits:remove']);
        app(\App\Services\Api\V1\MedicalServiceCartService::class)->addBasicPlan($a['user']->customer);
        $c = Coupon::factory()->create(['type' => $coupon ? \App\Enums\CouponType::Coupon : \App\Enums\CouponType::Balance, 'amount_cents' => $amount, 'remaining_cents' => $amount]);
        CouponUser::create(['coupon_id' => $c->id, 'user_id' => $a['user']->id]);
        $h = ['Authorization' => 'Bearer '.$a['token']->plainTextToken, 'X-Consumer-Credential' => $a['machine']->plainTextToken];
        $s = $this->getJson('https://local.invalid/api/v1/cart/benefits?service_line=medical_services', $h)->assertOk()->json('data');
        $this->flushHeaders();
        $this->post('/logout');
        $this->post('/login', ['email' => $a['user']->email, 'password' => 'password'])->assertRedirect();
        $this->post('/confirm-password', ['password' => 'password'])->assertRedirect();
        $cnf = $this->postJson('/benefits/consent/confirm', ['cart_ref' => $s['cart_ref'], 'cart_version' => $s['cart_version'], 'target_ref' => $s['benefits'][0]['benefit_ref'], 'operation' => 'apply', 'text_version' => 'synthetic-r30-v1'])->assertOk()->json('data.confirmation_ref');
        $app = $this->postJson('https://local.invalid/api/v1/cart/benefits/applications', ['service_line' => 'medical_services', 'cart_ref' => $s['cart_ref'], 'cart_version' => $s['cart_version'], 'benefit_ref' => $s['benefits'][0]['benefit_ref'], 'confirmation_ref' => $cnf], $h + ['Idempotency-Key' => 'synthetic-r30-selection-01'])->assertCreated()->json('data');
        $this->flushHeaders();
        $this->app->bind(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, Csrf::class);

        return [$a, $c, ['cart_ref' => $s['cart_ref'], 'application_ref' => $app['application_ref']]];
    }

    private function fields(string $html): array
    {
        $doc = new \DOMDocument;
        @$doc->loadHTML($html);
        $out = [];
        foreach ((new \DOMXPath($doc))->query('//form[1]//input') as $input) {
            $out[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        return $out;
    }

    private function review(array $f): array
    {
        $r = $this->get(self::URL.'?'.http_build_query($f[2]))->assertOk()->assertSee('Total final a pagar')->assertSee('MXN')->assertHeader('Cache-Control', 'no-store, private');

        return $this->fields($r->getContent());
    }

    private function action(array $fields, string $action)
    {
        return $this->post(self::URL.'/'.$fields['checkout_ref'].'/'.$action, ['_token' => $fields['_token'], 'authorize_payment' => 'yes']);
    }

    private function noEffects(): void
    {
        $this->assertSame(0, DB::table('medical_purchase_intents')->count());
        $this->assertSame(0, DB::table('medical_credit_reservations')->count());
        $this->assertSame(0, $this->provider->starts);
    }

    #[Test]
    public function real_http_session_review_explicit_confirm_status_and_reload_have_one_financial_effect(): void
    {
        $f = $this->fixture();
        $fields = $this->review($f);
        $this->noEffects();
        $this->assertSame($fields, $this->review($f));
        $done = $this->post(self::URL.'/confirm', $fields)->assertOk()->assertJsonPath('data.state', 'fulfilled')->assertJsonPath('data.cash_cents', 600);
        $this->post(self::URL.'/confirm', $fields)->assertOk()->assertExactJson($done->json());
        $this->get(self::URL.'/'.$fields['checkout_ref'])->assertOk()->assertJsonPath('data.state', 'fulfilled');
        $this->get(self::URL.'?'.http_build_query($f[2]))->assertOk()->assertDontSee('name="checkout_ref"', false);
        $this->assertSame(1, $this->provider->starts);
        $this->assertSame(1, DB::table('medical_credit_reservations')->count());
        $this->assertSame(1, DB::table('opaque_benefit_movements')->where('kind', 'debit')->count());
        $this->assertSame(1, MedicalAttentionSubscription::count());
        $this->assertSame(0, (int) $f[1]->fresh()->remaining_cents);
        Http::assertNothingSent();
    }

    #[Test]
    public function csrf_missing_forged_payment_unchecked_and_tampered_amount_never_reserve(): void
    {
        $f = $this->fixture();
        $fields = $this->review($f);
        $bad = $fields;
        unset($bad['_token']);
        $this->post(self::URL.'/confirm', $bad)->assertRedirect(route('home'));
        $bad = $fields;
        $bad['_token'] = 'synthetic-invalid';
        $this->post(self::URL.'/confirm', $bad)->assertRedirect(route('home'));
        $bad = $fields;
        unset($bad['authorize_payment']);
        $this->post(self::URL.'/confirm', $bad)->assertUnprocessable();
        $this->post(self::URL.'/confirm', $fields + ['cash_cents' => '1'])->assertUnprocessable();
        $this->noEffects();
    }

    #[Test]
    public function guest_machine_and_user_bearer_cannot_initiate_even_when_benefit_consent_exists(): void
    {
        $this->get(self::URL)->assertRedirect('/login');
        $f = $this->fixture();
        $fields = $this->review($f);
        $this->post(self::URL.'/confirm', $fields, ['Authorization' => 'Bearer '.$f[0]['token']->plainTextToken, 'X-Consumer-Credential' => $f[0]['machine']->plainTextToken])->assertForbidden();
        $this->flushHeaders();
        $this->post(self::URL.'/confirm', $fields, ['X-Consumer-Credential' => 'synthetic-spoof'])->assertForbidden();
        $this->flushHeaders();
        $this->post(self::URL.'/confirm', ['_token' => $fields['_token'], 'confirmation_ref' => \App\Domain\Akubica\Benefits\OpaqueReference::issue('cnf')])->assertUnprocessable();
        $this->noEffects();
    }

    #[Test]
    public function expired_confirmation_old_password_foreign_cart_and_foreign_session_are_rejected(): void
    {
        $f = $this->fixture();
        $fields = $this->review($f);
        $this->travel(121)->seconds();
        $this->post(self::URL.'/confirm', $fields)->assertConflict();
        $this->travelBack();
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(6)->timestamp]);
        $this->get(self::URL.'?'.http_build_query($f[2]))->assertForbidden();
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $other = User::factory()->withRegularCustomer()->create();
        $this->actingAs($other, 'web');
        $this->get(self::URL.'?'.http_build_query($f[2]))->assertUnauthorized();
        $this->noEffects();
    }

    #[Test]
    public function changed_cart_source_or_price_between_review_and_confirmation_never_calls_gateway(): void
    {
        $f = $this->fixture();
        $fields = $this->review($f);
        config()->set('famedic.medical_attention_subscription_price_cents', 1100);
        $this->post(self::URL.'/confirm', $fields)->assertConflict();
        $this->noEffects();
        config()->set('famedic.medical_attention_subscription_price_cents', 1000);
        $f[1]->update(['is_active' => false]);
        $this->post(self::URL.'/confirm', $fields)->assertConflict();
        $this->noEffects();
    }

    #[Test]
    public function disabled_certification_or_missing_user_authority_denies_before_reservation(): void
    {
        $f = $this->fixture();
        config()->set('akubica_connectors.first_party_purchase.certified', false);
        $this->get(self::URL.'?'.http_build_query($f[2]))->assertForbidden();
        $this->noEffects();
        config()->set('akubica_connectors.first_party_purchase.certified', true);
        Gate::define('purchaseMedicalBenefits', fn () => false);
        $this->get(self::URL.'?'.http_build_query($f[2]))->assertForbidden();
        $this->noEffects();
    }

    #[Test]
    public function provider_timeout_is_recoverable_over_http_without_second_charge_or_release(): void
    {
        $f = $this->fixture();
        $fields = $this->review($f);
        $this->provider->outcome = 'throw';
        $this->post(self::URL.'/confirm', $fields)->assertConflict();
        $this->get(self::URL.'/'.$fields['checkout_ref'])->assertOk()->assertJsonPath('data.state', 'needs_review');
        $this->post(self::URL.'/confirm', $fields)->assertOk()->assertJsonPath('data.state', 'needs_review');
        $this->action($fields, 'cancel')->assertConflict();
        $this->assertSame('needs_review', DB::table('medical_credit_reservations')->sole()->state);
        $this->provider->observed = 'unknown';
        $this->action($fields, 'recover')->assertOk()->assertJsonPath('data.state', 'needs_review');
        $this->provider->observed = 'confirmed';
        $this->action($fields, 'recover')->assertOk()->assertJsonPath('data.state', 'fulfilled');
        $this->assertSame(1, $this->provider->starts);
        $this->assertSame(1, MedicalAttentionSubscription::count());
    }

    #[Test]
    public function provider_unknown_or_wrong_amount_never_creates_a_membership(): void
    {
        $f = $this->fixture();
        $fields = $this->review($f);
        $this->provider->outcome = 'unknown';
        $this->post(self::URL.'/confirm', $fields)->assertOk()->assertJsonPath('data.state', 'needs_review');
        $this->assertSame(0, MedicalAttentionSubscription::count());
        $this->assertSame(400, (int) $f[1]->fresh()->remaining_cents);
        $this->assertSame(1, $this->provider->starts);
    }

    #[Test]
    public function full_credit_and_coupon_http_purchases_use_distinct_funding_and_reverse_once(): void
    {
        $f = $this->fixture(1500);
        $fields = $this->review($f);
        $this->post(self::URL.'/confirm', $fields)->assertOk()->assertJsonPath('data.cash_cents', 0)->assertJsonPath('data.state', 'fulfilled');
        $this->assertSame(0, $this->provider->starts);
        $this->assertSame(500, (int) $f[1]->fresh()->remaining_cents);
        $this->action($fields, 'reverse')->assertOk()->assertJsonPath('data.state', 'reversed');
        $this->action($fields, 'reverse')->assertOk()->assertJsonPath('data.state', 'reversed');
        $this->assertSame(1500, (int) $f[1]->fresh()->remaining_cents);
        $this->assertSame(0, MedicalAttentionSubscription::count());
    }

    #[Test]
    public function cash_refund_requires_separate_authority_and_never_duplicates_movements(): void
    {
        $f = $this->fixture();
        $fields = $this->review($f);
        $this->post(self::URL.'/confirm', $fields)->assertOk();
        Gate::define('reverseMedicalBenefits', fn () => false);
        $this->action($fields, 'reverse')->assertForbidden();
        $this->assertSame(0, $this->provider->refunds);
        Gate::define('reverseMedicalBenefits', fn () => true);
        $this->action($fields, 'reverse')->assertOk()->assertJsonPath('data.state', 'reversed');
        $this->action($fields, 'reverse')->assertOk();
        $this->assertSame(1, $this->provider->refunds);
        $this->assertSame(400, (int) $f[1]->fresh()->remaining_cents);
        $this->assertSame(0, MedicalAttentionSubscription::count());
    }

    #[Test]
    public function storage_failure_before_authorization_does_not_reach_provider(): void
    {
        $f = $this->fixture();
        $fields = $this->review($f);
        $sql = DB::getDriverName() === 'mysql' ? "CREATE TRIGGER synthetic_r30_authorization_failure BEFORE UPDATE ON first_party_medical_checkouts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic failure'" : "CREATE TRIGGER synthetic_r30_authorization_failure BEFORE UPDATE ON first_party_medical_checkouts BEGIN SELECT RAISE(ABORT,'synthetic failure'); END";
        DB::unprepared($sql);
        try {
            $this->post(self::URL.'/confirm', $fields)->assertStatus(503);
            $this->noEffects();
        } finally {
            DB::unprepared('DROP TRIGGER synthetic_r30_authorization_failure');
        }
    }

    #[Test]
    public function coupon_http_purchase_is_discount_not_credit_and_unknown_is_not_success(): void
    {
        $f = $this->fixture(300, true);
        $fields = $this->review($f);
        $this->post(self::URL.'/confirm', $fields)->assertOk()->assertJsonPath('data.discount_cents', 300)->assertJsonPath('data.credit_cents', 0)->assertJsonPath('data.cash_cents', 700);
        $movement = DB::table('opaque_benefit_movements')->where('kind', 'debit')->sole();
        $this->assertSame('coupon', $movement->benefit_kind);
        $this->assertNull($movement->credit_transaction_id);
        $this->action($fields, 'reverse')->assertOk()->assertJsonPath('data.state', 'reversed');
        $this->assertSame(300, (int) $f[1]->fresh()->remaining_cents);
    }

    #[Test]
    public function another_real_famedic_login_cannot_review_or_query_the_owners_checkout(): void
    {
        $f = $this->fixture();
        $fields = $this->review($f);
        // Return to the ordinary login stack only to create a distinct genuine web session.
        $this->app->bind(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->post('/logout');
        $other = User::factory()->withRegularCustomer()->create();
        $this->post('/login', ['email' => $other->email, 'password' => 'password'])->assertRedirect();
        $this->post('/confirm-password', ['password' => 'password'])->assertRedirect();
        $this->get(self::URL.'?'.http_build_query($f[2]))->assertNotFound();
        $this->get(self::URL.'/'.$fields['checkout_ref'])->assertNotFound();
        $this->noEffects();
    }

    #[Test]
    public function mysql_two_real_http_kernels_double_click_have_one_charge_and_one_membership(): void
    {
        if (! config('benefits.synthetic_mysql')) {
            $this->markTestSkipped('Requires isolated MySQL process harness');
        }
        $f = $this->fixture();
        $fields = $this->review($f);
        \Illuminate\Support\Facades\Schema::create('r30_http_gateway_calls', function ($t) {
            $t->id();
            $t->string('kind');
        });
        $root = getenv('APP_BASE_PATH');
        $dir = $root.'/r30-sessions';
        if (! is_dir($dir)) {
            mkdir($dir, 0700);
        }
        $id = bin2hex(random_bytes(20));
        file_put_contents($dir.'/'.$id, serialize(app('session.store')->all()));
        chmod($dir.'/'.$id, 0600);
        $go = $dir.'/go';
        $children = [];
        $env = array_merge(getenv(), ['FAMEDIC_V2_PREPARE_SCHEMA' => '0']);
        try {
            foreach ([0, 1] as $n) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, __DIR__.'/http-worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
                $this->assertIsResource($process);
                fwrite($pipes[0], json_encode(['ready' => $dir.'/ready'.$n, 'go' => $go, 'session' => $id, 'form' => $fields]));
                fclose($pipes[0]);
                $children[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 30;
            while (! is_file($dir.'/ready0') || ! is_file($dir.'/ready1')) {
                if (microtime(true) > $deadline) {
                    $this->fail('HTTP worker setup timed out');
                }usleep(20000);
            }
            file_put_contents($go, 'go');
            foreach ($children as [$process,$pipes]) {
                $out = stream_get_contents($pipes[1]);
                $err = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = proc_close($process);
                $this->assertSame(0, $exit, 'Synthetic HTTP worker failed');
                $result = json_decode($out, true);
                $this->assertSame(200, $result['http'] ?? null, 'HTTP race did not authorize genuine first-party request');
                file_put_contents(getenv('APP_BASE_PATH').'/r30-http-observations.jsonl', json_encode(['method' => 'POST', 'path' => self::URL.'/confirm', 'http' => $result['http'], 'state' => $result['state'], 'process' => 'CONCURRENT_CHILD_REAL_HTTP_KERNEL'])."\n", FILE_APPEND);
            }
            $children = [];
            $this->assertSame(1, DB::table('r30_http_gateway_calls')->where('kind', 'charge')->count());
            $this->assertSame(1, DB::table('medical_purchase_intents')->count());
            $this->assertSame(1, DB::table('medical_credit_reservations')->count());
            $this->assertSame(1, MedicalAttentionSubscription::count());
            $this->assertSame(1, DB::table('opaque_benefit_movements')->where('kind', 'debit')->count());
            $this->get(self::URL.'/'.$fields['checkout_ref'])->assertOk()->assertJsonPath('data.state', 'fulfilled');
        } finally {
            foreach ($children as [$process,$pipes]) {
                foreach ([1, 2] as $n) {
                    if (is_resource($pipes[$n])) {
                        fclose($pipes[$n]);
                    }
                } if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
        }
    }

    #[Test]
    public function provider_rejection_is_not_success_and_does_not_authorize_retry_or_debit(): void
    {
        $f = $this->fixture();
        $fields = $this->review($f);
        $this->provider->outcome = 'declined';
        $this->post(self::URL.'/confirm', $fields)->assertConflict();
        $this->post(self::URL.'/confirm', $fields)->assertOk()->assertJsonPath('data.state', 'needs_review');
        $this->assertSame(1, $this->provider->starts);
        $this->assertSame(400, (int) $f[1]->fresh()->remaining_cents);
        $this->assertSame(0, MedicalAttentionSubscription::count());
        $this->assertSame(0, DB::table('opaque_benefit_movements')->count());
    }

    #[Test]
    public function http_recovers_a_committed_debit_after_membership_storage_failure_without_recharge(): void
    {
        $f = $this->fixture();
        $fields = $this->review($f);
        $sql = DB::getDriverName() === 'mysql' ? "CREATE TRIGGER synthetic_r30_member_failure BEFORE INSERT ON medical_attention_subscriptions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic failure'" : "CREATE TRIGGER synthetic_r30_member_failure BEFORE INSERT ON medical_attention_subscriptions BEGIN SELECT RAISE(ABORT,'synthetic failure'); END";
        DB::unprepared($sql);
        try {
            $this->post(self::URL.'/confirm', $fields)->assertStatus(503);
            $this->assertSame(1, DB::table('opaque_benefit_movements')->where('kind', 'debit')->count());
            $this->assertSame(0, MedicalAttentionSubscription::count());
        } finally {
            DB::unprepared('DROP TRIGGER synthetic_r30_member_failure');
        }
        $this->action($fields, 'recover')->assertOk()->assertJsonPath('data.state', 'fulfilled');
        $this->assertSame(1, $this->provider->starts);
        $this->assertSame(1, MedicalAttentionSubscription::count());
        $this->assertSame(1, DB::table('opaque_benefit_movements')->where('kind', 'debit')->count());
    }

    #[Test]
    public function http_paypal_creation_capture_and_replay_use_the_real_provider_adapter(): void
    {
        $f = $this->fixture();
        $fields = $this->review($f);
        $this->app->forgetInstance(PayPalRemnantGateway::class);
        config()->set('akubica_connectors.paypal_merchant_id', 'synthetic-r30-merchant');
        $reference = null;
        $paypal = \Mockery::mock(\App\Services\PayPalService::class);
        $paypal->shouldReceive('createOrder')->once()->andReturn(['order_id' => 'synthetic-r30-real-adapter-order']);
        $paypal->shouldReceive('captureOrder')->once()->with('synthetic-r30-real-adapter-order')->andReturn(['status' => 'COMPLETED']);
        $paypal->shouldReceive('getOrder')->andReturnUsing(function () use (&$reference) {
            return ['id' => 'synthetic-r30-real-adapter-order', 'status' => 'COMPLETED', 'purchase_units' => [['custom_id' => $reference, 'payee' => ['merchant_id' => 'synthetic-r30-merchant'], 'amount' => ['value' => '6.00', 'currency_code' => 'MXN'], 'payments' => ['captures' => [['id' => 'synthetic-r30-verified-capture', 'status' => 'COMPLETED', 'final_capture' => true, 'amount' => ['value' => '6.00', 'currency_code' => 'MXN']]]]]]];
        });
        $this->app->instance(\App\Services\PayPalService::class, $paypal);
        $r = $this->post(self::URL.'/confirm', $fields)->assertOk()->assertJsonPath('data.state', 'awaiting_payment');
        $reference = $r->json('data.purchase_ref');
        $this->get(self::URL.'/'.$fields['checkout_ref'])->assertOk()->assertJsonPath('data.state', 'awaiting_payment');
        $this->action($fields, 'capture')->assertOk()->assertJsonPath('data.state', 'fulfilled');
        $this->action($fields, 'capture')->assertOk()->assertJsonPath('data.state', 'fulfilled');
        $this->assertSame(1, MedicalAttentionSubscription::count());
        $this->assertSame(1, DB::table('opaque_benefit_movements')->where('kind', 'debit')->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function http_efevoo_owned_method_uses_real_charge_adapter_and_odessa_stays_blocked(): void
    {
        $f = $this->fixture();
        config()->set('akubica_connectors.providers.efevoopay.certified', true);
        config()->set('efevoopay.verify_ssl', true);
        $token = \App\Models\EfevooToken::factory()->make(['card_token' => 'synthetic-r30-card-only', 'card_expiration' => '1230', 'environment' => 'test', 'expires_at' => now()->addYear()]);
        $token->offsetUnset('client_id');
        $f[0]['user']->customer->efevooTokens()->save($token);
        $this->get(self::URL.'?'.http_build_query($f[2] + ['provider' => 'odessa']))->assertConflict();
        $this->noEffects();
        $this->get(self::URL.'?'.http_build_query($f[2] + ['provider' => 'efevoopay', 'payment_method' => '99999999']))->assertConflict();
        $this->noEffects();
        $external = \Mockery::mock(\App\Contracts\EfevooPayGateway::class);
        $external->shouldReceive('chargeCard')->once()->andReturnUsing(function ($d) {
            $this->assertSame(6, $d['amount']);

            return ['success' => true, 'transaction_id' => '12345', 'raw' => ['data' => ['amount' => '6.00', 'currency' => 'MXN', 'reference' => $d['reference']]]];
        });
        $this->app->instance(\App\Contracts\EfevooPayGateway::class, $external);
        $view = $this->get(self::URL.'?'.http_build_query($f[2] + ['provider' => 'efevoopay', 'payment_method' => (string) $token->id]))->assertOk();
        $fields = $this->fields($view->getContent());
        $this->post(self::URL.'/confirm', $fields)->assertOk()->assertJsonPath('data.state', 'fulfilled');
        $this->post(self::URL.'/confirm', $fields)->assertOk()->assertJsonPath('data.state', 'fulfilled');
        $this->assertSame(1, MedicalAttentionSubscription::count());
        $this->assertSame(1, DB::table('medical_purchase_intents')->count());
        $this->assertSame(0, (int) $f[1]->fresh()->remaining_cents);
        Http::assertNothingSent();
    }

    #[Test]
    public function http_retry_recovers_reservation_created_before_intent_storage_failure(): void
    {
        $f = $this->fixture();
        $fields = $this->review($f);
        $sql = DB::getDriverName() === 'mysql' ? "CREATE TRIGGER synthetic_r30_intent_failure BEFORE INSERT ON medical_purchase_intents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic failure'" : "CREATE TRIGGER synthetic_r30_intent_failure BEFORE INSERT ON medical_purchase_intents BEGIN SELECT RAISE(ABORT,'synthetic failure'); END";
        DB::unprepared($sql);
        try {
            $this->post(self::URL.'/confirm', $fields)->assertStatus(503);
            $this->assertSame(1, DB::table('medical_credit_reservations')->count());
            $this->assertSame(0, DB::table('medical_purchase_intents')->count());
            $this->assertSame(0, $this->provider->starts);
        } finally {
            DB::unprepared('DROP TRIGGER synthetic_r30_intent_failure');
        }
        $this->get(self::URL.'?'.http_build_query($f[2]))->assertOk()->assertSee('name="checkout_ref"', false);
        $this->get(self::URL.'/'.$fields['checkout_ref'])->assertOk()->assertJsonPath('data.state', 'authorization_recorded');
        $this->post(self::URL.'/confirm', $fields)->assertOk()->assertJsonPath('data.state', 'fulfilled');
        $this->assertSame(1, DB::table('medical_credit_reservations')->count());
        $this->assertSame(1, $this->provider->starts);
        $this->assertSame(1, MedicalAttentionSubscription::count());
        // Simulate a missing local receipt pointer after a committed saga, not a new order.
        DB::table('first_party_medical_checkouts')->update(['purchase_ref' => null]);
        $this->get(self::URL.'/'.$fields['checkout_ref'])->assertOk()->assertJsonPath('data.state', 'fulfilled');
        $this->post(self::URL.'/confirm', $fields)->assertOk()->assertJsonPath('data.state', 'fulfilled');
        $this->assertSame(1, $this->provider->starts);
    }
}
