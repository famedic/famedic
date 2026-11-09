<?php

namespace Famedic\OperationalConnectors;

use App\Contracts\EfevooPayGateway;
use App\Models\EfevooToken;
use App\Models\OdessaAfiliateAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MedicalCheckout\EfevooRemnantGateway;
use App\Services\MedicalCheckout\OdessaRemnantGateway;
use App\Services\MedicalCheckout\PayPalRemnantGateway;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProviderAdaptersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Queue::fake();
        Notification::fake();
        Http::preventStrayRequests();
        config()->set('efevoopay.verify_ssl', true);
    }

    private function pending($c): Transaction
    {
        return Transaction::create(['transaction_amount_cents' => 600, 'payment_status' => 'pending', 'payment_method' => 'efevoopay', 'reference_id' => 'synthetic-provider-intent', 'details' => ['customer_id' => $c->id]]);
    }

    #[Test]
    public function efevoo_uses_real_charge_action_with_durable_transaction_and_exact_remainder(): void
    {
        $u = User::factory()->withRegularCustomer()->create();
        $c = $u->customer;
        $token = EfevooToken::factory()->make(['card_token' => 'synthetic-card-token-not-real', 'card_expiration' => '1230', 'environment' => 'test', 'expires_at' => now()->addYear()]);
        $token->offsetUnset('client_id');
        $c->efevooTokens()->save($token);
        $gateway = \Mockery::mock(EfevooPayGateway::class);
        $gateway->shouldReceive('chargeCard')->once()->with(\Mockery::on(fn ($x) => $x['amount'] === 6 && $x['reference'] === 'synthetic-provider-intent'))->andReturn(['success' => true, 'transaction_id' => '12345', 'raw' => ['data' => ['amount' => '6.00', 'currency' => 'MXN', 'reference' => 'synthetic-provider-intent']]]);
        $this->app->instance(EfevooPayGateway::class, $gateway);
        $tx = $this->pending($c);
        $o = app(EfevooRemnantGateway::class)->start($c, $tx, 'synthetic-provider-intent', 600, (string) $token->id);
        $this->assertSame('confirmed', $o->outcome);
        $this->assertSame(600, $o->cashCents);
        $this->assertSame(1, Transaction::count());
        $this->assertSame('12345', $tx->fresh()->gateway_transaction_id);
        Http::assertNothingSent();
    }

    #[Test]
    public function efevoo_missing_echo_never_turns_transaction_status_into_authority(): void
    {
        $u = User::factory()->withRegularCustomer()->create();
        $c = $u->customer;
        $token = EfevooToken::factory()->make(['card_token' => 'synthetic-card-token-not-real', 'card_expiration' => '1230', 'environment' => 'test', 'expires_at' => now()->addYear()]);
        $token->offsetUnset('client_id');
        $c->efevooTokens()->save($token);
        $gateway = \Mockery::mock(EfevooPayGateway::class);
        $gateway->shouldReceive('chargeCard')->once()->andReturn(['success' => true, 'transaction_id' => '12345', 'raw' => ['data' => []]]);
        $this->app->instance(EfevooPayGateway::class, $gateway);
        $o = app(EfevooRemnantGateway::class)->start($c, $this->pending($c), 'synthetic-provider-intent', 600, (string) $token->id);
        $this->assertSame('unknown', $o->outcome);
        Http::assertNothingSent();
    }

    #[Test]
    public function odessa_uses_real_charge_action_with_tls_and_exact_remainder_but_no_email_refund_proof(): void
    {
        $u = User::factory()->withRegularCustomer()->create();
        $account = OdessaAfiliateAccount::factory()->create();
        $c = $u->customer;
        $c->customerable()->associate($account);
        $c->save();
        $c = $c->fresh();
        $get = \Mockery::mock(\App\Actions\Odessa\GetOdessaPrivateTokenAction::class);
        $get->shouldReceive('__invoke')->once()->andReturn('synthetic-only-token');
        $this->app->instance(\App\Actions\Odessa\GetOdessaPrivateTokenAction::class, $get);
        $balance = \Mockery::mock(\App\Actions\Odessa\CheckBalanceAction::class);
        $balance->shouldReceive('__invoke')->once()->with('synthetic-only-token', 600)->andReturn(true);
        $this->app->instance(\App\Actions\Odessa\CheckBalanceAction::class, $balance);
        config()->set('services.odessa.url', 'https://odessa.synthetic.invalid/');
        $tx = $this->pending($c);
        Http::fake(['odessa.synthetic.invalid/*' => Http::response(['response' => ['errorCode' => 0, 'amount' => '6.00', 'currency' => 'MXN', 'transaction' => (string) $tx->id, 'transactionOds' => 'synthetic-odessa-event']], 200)]);
        $adapter = app(OdessaRemnantGateway::class);
        $o = $adapter->start($c, $tx, 'synthetic-provider-intent', 600, 'odessa');
        $this->assertSame('confirmed', $o->outcome);
        $this->assertSame('unknown', $adapter->reverse($c, $tx, 'synthetic-provider-intent', 600)->outcome);
        $this->assertSame(1, Transaction::count());
        Http::assertSent(fn ($r) => $r['request']['amount'] === 6 && (int) $r['request']['transaction'] === (int) $tx->id);
        Notification::assertNothingSent();
    }

    #[Test]
    public function payment_amount_parser_uses_exact_decimal_strings(): void
    {
        $this->assertSame(601, PayPalRemnantGateway::cents('6.01'));
        foreach (['6.001', '6', '-6.00', 6.0, '6.00'."\n"] as $value) {
            $this->assertNull(PayPalRemnantGateway::cents($value));
        }
    }

    #[Test]
    public function efevoo_search_service_is_used_for_reconciliation_and_ambiguous_results_remain_unknown(): void
    {
        $c = User::factory()->withRegularCustomer()->create()->customer;
        $tx = $this->pending($c);
        $tx->update(['gateway_transaction_id' => '12345']);
        $service = \Mockery::mock(\App\Services\EfevooPayService::class);
        $row = ['id' => '12345', 'amount' => '6.00', 'currency' => 'MXN', 'reference' => 'synthetic-provider-intent', 'status' => 'COMPLETED'];
        $service->shouldReceive('searchTransactions')->times(3)->with(['transaction_id' => 12345])->andReturn(['success' => true, 'data' => ['data' => [$row]]], ['success' => true, 'data' => ['data' => [$row, $row]]], ['success' => true, 'data' => ['data' => [array_replace($row, ['status' => 'REFUNDED', 'refund_id' => 'synthetic-refund'])]]]);
        $this->app->instance(\App\Services\EfevooPayService::class, $service);
        $adapter = app(EfevooRemnantGateway::class);
        $this->assertSame('confirmed', $adapter->observe($c, $tx, 'synthetic-provider-intent', 600)->outcome);
        $this->assertSame('unknown', $adapter->observe($c, $tx, 'synthetic-provider-intent', 600)->outcome);
        $this->assertSame('reversed', $adapter->observe($c, $tx, 'synthetic-provider-intent', 600)->outcome);
        Http::assertNothingSent();
    }

    #[Test]
    public function new_efevoo_flow_rejects_disabled_tls_before_any_charge(): void
    {
        $c = User::factory()->withRegularCustomer()->create()->customer;
        $tx = $this->pending($c);
        config()->set('efevoopay.verify_ssl', false);
        try {
            app(EfevooRemnantGateway::class)->start($c, $tx, 'synthetic-provider-intent', 600, 'unused');
            $this->fail('TLS disabled');
        } catch (\App\Domain\Akubica\Benefits\BenefitFailure $e) {
            $this->assertSame('GATEWAY_CERTIFICATION_PENDING', $e->errorCode);
        } Http::assertNothingSent();
    }
}
