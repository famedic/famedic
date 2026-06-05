<?php

namespace App\Actions\MedicalAttention;

use App\Actions\Payments\ChargePaymentMethodAction;
use App\Actions\Odessa\ChargeOdessaAction;
use App\Actions\Transactions\RefundTransactionAction;
use App\Exceptions\UnmatchingTotalPriceException;
use App\Models\Customer;
use App\Models\MedicalAttentionSubscription;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseRegularSubscriptionAction
{
    private CreateRegularSubscriptionAction $createRegularSubscriptionAction;
    private ChargeOdessaAction $chargeOdessaAction;
    private ChargePaymentMethodAction $chargePaymentMethodAction;
    private RefundTransactionAction $refundTransactionAction;

    public function __construct(
        CreateRegularSubscriptionAction $createRegularSubscriptionAction,
        ChargeOdessaAction $chargeOdessaAction,
        ChargePaymentMethodAction $chargePaymentMethodAction,
        RefundTransactionAction $refundTransactionAction
    ) {
        $this->createRegularSubscriptionAction = $createRegularSubscriptionAction;
        $this->chargeOdessaAction = $chargeOdessaAction;
        $this->chargePaymentMethodAction = $chargePaymentMethodAction;
        $this->refundTransactionAction = $refundTransactionAction;
    }

    public function __invoke(
        Customer $customer,
        string $paymentMethod,
        int $totalCents
    ): MedicalAttentionSubscription {

        $expectedTotalCents = config('famedic.medical_attention_subscription_price_cents');

        Log::info('🔵 Starting Medical Attention Subscription Purchase', [
            'customer_id' => $customer->id,
            'payment_method' => $paymentMethod,
            'total_cents_received' => $totalCents,
            'expected_total_cents' => $expectedTotalCents,
        ]);

        // 🔒 Validar precio
        if ($totalCents !== $expectedTotalCents) {
            Log::warning('⛔ Price mismatch detected', [
                'customer_id' => $customer->id,
                'received' => $totalCents,
                'expected' => $expectedTotalCents,
            ]);

            throw new UnmatchingTotalPriceException();
        }

        $transaction = null;

        try {

            /**
             * ==============================
             * 1️⃣ COBRO
             * ==============================
             */
            DB::beginTransaction();

            Log::info('💳 Attempting charge...', [
                'customer_id' => $customer->id,
                'amount_cents' => $totalCents,
                'gateway' => $paymentMethod === 'odessa' ? 'odessa' : 'efevoopay',
            ]);

            $transaction = $this->chargeAndCreateTransaction(
                $totalCents,
                $paymentMethod,
                $customer
            );

            Log::info('✅ Charge successful', [
                'transaction_id' => $transaction->id,
                'provider' => $transaction->provider ?? 'unknown',
                'amount_cents' => $totalCents,
            ]);

            DB::commit();

            /**
             * ==============================
             * 2️⃣ CREAR SUSCRIPCIÓN
             * ==============================
             */
            DB::beginTransaction();

            Log::info('📦 Creating subscription...', [
                'customer_id' => $customer->id,
            ]);

            $subscription = ($this->createRegularSubscriptionAction)($customer);

            $subscription->transactions()->attach($transaction);

            $customer->update([
                'medical_attention_subscription_expires_at' => $subscription->end_date
            ]);

            Log::info('🎉 Subscription created successfully', [
                'subscription_id' => $subscription->id,
                'transaction_id' => $transaction->id,
                'expires_at' => $subscription->end_date,
            ]);

            DB::commit();

            return $subscription;

        } catch (\Throwable $th) {

            DB::rollBack();

            Log::error('❌ Error during subscription purchase', [
                'customer_id' => $customer->id,
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);

            // 🔁 Refund automático
            if ($transaction) {
                try {

                    Log::warning('🔁 Attempting automatic refund...', [
                        'transaction_id' => $transaction->id,
                    ]);

                    ($this->refundTransactionAction)($transaction);

                    Log::info('💰 Refund successful', [
                        'transaction_id' => $transaction->id,
                    ]);

                } catch (\Throwable $refundError) {

                    Log::critical('🚨 Refund failed after subscription error', [
                        'transaction_id' => $transaction->id ?? null,
                        'refund_error' => $refundError->getMessage(),
                    ]);
                }
            }

            throw $th;
        }
    }

    /**
     * Decide qué gateway usar sin afectar Odessa
     */
    private function chargeAndCreateTransaction(
        int $amountCents,
        string $paymentMethod,
        Customer $customer
    ): Transaction {

        if ($paymentMethod === 'odessa') {

            Log::info('🟦 Using Odessa gateway', [
                'customer_id' => $customer->id,
                'amount_cents' => $amountCents,
            ]);

            return ($this->chargeOdessaAction)(
                $customer->customerable,
                $amountCents
            );
        }

        Log::info('🟩 Using card payment gateway', [
            'customer_id' => $customer->id,
            'amount_cents' => $amountCents,
            'payment_method_token' => $paymentMethod,
        ]);

        return ($this->chargePaymentMethodAction)(
            $customer,
            $amountCents,
            $paymentMethod
        );
    }
}
