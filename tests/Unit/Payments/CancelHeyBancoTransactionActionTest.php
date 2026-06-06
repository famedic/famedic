<?php

namespace Tests\Unit\Payments;

use App\Actions\Payments\HeyBanco\CancelHeyBancoTransactionAction;
use App\Models\PaymentTransaction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CancelHeyBancoTransactionActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'heybanco.enabled' => true,
            'heybanco.adq_url' => 'https://testcolecto.banregio.com/adq/',
            'heybanco.token_media_id' => 'JCZSH8TV',
            'heybanco.mode' => 'AUT',
            'heybanco.provider_key' => 'hey_banco',
            'heybanco.currency' => 'MXN',
        ]);
    }

    public function test_it_persists_cancellation_in_payment_transactions(): void
    {
        Http::fake([
            'testcolecto.banregio.com/*' => Http::response('', 200, [
                'BNRG_CODIGO_PROC' => 'A',
                'BNRG_REFERENCIA' => 'REF-CANCEL-777',
                'BNRG_FOLIO' => 'FM-CAN777',
                'BNRG_TEXTO' => 'Cancelacion%20ok',
            ]),
        ]);

        $user = User::factory()->withRegularCustomer()->create();
        $customer = $user->customer;

        $transaction = Transaction::create([
            'transaction_amount_cents' => 13100,
            'payment_method' => 'hey_banco',
            'gateway' => 'hey_banco',
            'gateway_transaction_id' => 'REF-CHARGE-777',
            'gateway_status' => 'completed',
            'details' => [
                'customer_info' => [
                    'customer_id' => $customer->id,
                    'user_id' => $user->id,
                ],
                'payment_details' => [
                    'banregio_reference' => 'REF-CHARGE-777',
                ],
            ],
        ]);

        $paymentTransaction = app(CancelHeyBancoTransactionAction::class)(
            $transaction,
            $customer,
        );

        $this->assertSame('cancellation', $paymentTransaction->flow);
        $this->assertSame('approved', $paymentTransaction->status);
        $this->assertSame('REF-CHARGE-777', $paymentTransaction->previous_reference);
        $this->assertSame('REF-CANCEL-777', $paymentTransaction->reference);
        $this->assertDatabaseHas('payment_transactions', [
            'id' => $paymentTransaction->id,
            'flow' => 'cancellation',
            'previous_reference' => 'REF-CHARGE-777',
        ]);
    }
}
