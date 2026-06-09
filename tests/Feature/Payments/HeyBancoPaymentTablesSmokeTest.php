<?php

namespace Tests\Feature\Payments;

use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeyBancoPaymentTablesSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_methods_payment_transactions_and_attempts_persist_for_hey_banco(): void
    {
        $user = User::factory()->withRegularCustomer()->create();
        $customer = $user->customer;

        $paymentTransaction = PaymentTransaction::create([
            'user_id' => $user->id,
            'provider' => 'hey_banco',
            'flow' => 'token_creation',
            'folio' => 'FMTEST000001',
            'reference' => 'REF-TOKEN-001',
            'amount' => 0,
            'currency' => 'MXN',
            'mode' => 'AUT',
            'status' => 'approved',
            'bnrg_codigo_proc' => 'A',
            'bnrg_texto' => 'Token creado',
            'raw_request' => [
                'BNRG_CMD_TRANS' => 'CREACION_TOKEN',
                'BNRG_NUMERO_TARJETA' => '****1096',
            ],
            'raw_response_headers' => [
                'BNRG_CODIGO_PROC' => 'A',
                'BNRG_TOKEN' => 'token-smoke-test',
            ],
        ]);

        $this->assertIsArray($paymentTransaction->fresh()->raw_request);
        $this->assertIsArray($paymentTransaction->fresh()->raw_response_headers);

        $paymentMethod = PaymentMethod::create([
            'user_id' => $user->id,
            'provider' => 'hey_banco',
            'provider_token' => 'token-smoke-test',
            'brand' => 'visa',
            'last4' => '1096',
            'exp_month' => '12',
            'exp_year' => '2026',
            'affiliation_id' => 'TEST-AFF',
            'media_id' => 'TEST-MEDIA',
            'status' => 'active',
            'alias' => 'visa-1096',
            'card_holder' => 'Titular Smoke',
            'created_from_transaction_id' => $paymentTransaction->id,
        ]);

        $paymentTransaction->update([
            'payment_method_id' => $paymentMethod->id,
        ]);

        $this->assertSame('hey_banco:'. $paymentMethod->id, $paymentMethod->publicId());
        $this->assertTrue($paymentMethod->createdFromTransaction->is($paymentTransaction));
        $this->assertTrue($paymentTransaction->fresh()->paymentMethod->is($paymentMethod));

        $attempt = PaymentAttempt::create([
            'customer_id' => $customer->id,
            'token_id' => $paymentMethod->id,
            'amount_cents' => 15000,
            'gateway' => 'hey_banco',
            'reference' => 'FM-'.$customer->id.'-smoke-001',
            'idempotency_key' => 'checkout-smoke-uuid-001',
            'status' => 'approved',
            'processor_code' => 'A',
            'processor_message' => 'Aprobada',
            'processor_transaction_id' => 'REF-CHARGE-001',
            'raw_response' => [
                'codigo_proc' => 'A',
                'referencia' => 'REF-CHARGE-001',
            ],
            'processed_at' => now(),
        ]);

        $this->assertIsArray($attempt->fresh()->raw_response);

        $this->assertDatabaseHas('payment_methods', [
            'id' => $paymentMethod->id,
            'provider' => 'hey_banco',
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('payment_transactions', [
            'id' => $paymentTransaction->id,
            'provider' => 'hey_banco',
            'payment_method_id' => $paymentMethod->id,
            'flow' => 'token_creation',
        ]);

        $this->assertDatabaseHas('payment_attempts', [
            'id' => $attempt->id,
            'gateway' => 'hey_banco',
            'idempotency_key' => 'checkout-smoke-uuid-001',
        ]);
    }
}
