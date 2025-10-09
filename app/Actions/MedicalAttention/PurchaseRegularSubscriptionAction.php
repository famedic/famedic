<?php

namespace App\Actions\MedicalAttention;

use App\Actions\Odessa\ChargeOdessaAction;
use App\Actions\Stripe\ChargeStripePaymentMethodAction;
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
    private ChargeStripePaymentMethodAction $chargeStripePaymentMethodAction;
    private RefundTransactionAction $refundTransactionAction;

    public function __construct(
        CreateRegularSubscriptionAction $createRegularSubscriptionAction,
        ChargeOdessaAction $chargeOdessaAction,
        ChargeStripePaymentMethodAction $chargeStripePaymentMethodAction,
        RefundTransactionAction $refundTransactionAction
    ) {
        $this->createRegularSubscriptionAction = $createRegularSubscriptionAction;
        $this->chargeOdessaAction = $chargeOdessaAction;
        $this->chargeStripePaymentMethodAction = $chargeStripePaymentMethodAction;
        $this->refundTransactionAction = $refundTransactionAction;
    }

    public function __invoke(Customer $customer, string $paymentMethod, int $totalCents): MedicalAttentionSubscription
    {
        $expectedTotalCents = config('famedic.medical_attention_subscription_price_cents');
        
        // Validate total matches expected price
        if ($totalCents !== $expectedTotalCents) {
            throw new UnmatchingTotalPriceException();
        }

        $transaction = null;
        
        try {
            // First, charge the customer
            DB::beginTransaction();
            
            $transaction = $this->chargeAndCreateTransaction($totalCents, $paymentMethod, $customer);
            
            DB::commit();
            
            // Then create the subscription and link it
            DB::beginTransaction();
            
            $subscription = ($this->createRegularSubscriptionAction)($customer);
            
            // Link transaction to subscription
            $subscription->transactions()->attach($transaction);
            
            // Update customer's medical attention expiration
            $customer->update(['medical_attention_subscription_expires_at' => $subscription->end_date]);
            
            Log::info('Regular subscription purchase completed', [
                'subscription_id' => $subscription->id,
                'transaction_id' => $transaction->id,
                'customer_id' => $customer->id,
                'total_cents' => $totalCents,
            ]);
            
            DB::commit();
            
            return $subscription;
            
        } catch (\Throwable $th) {
            DB::rollBack();
            
            if ($transaction) {
                ($this->refundTransactionAction)($transaction);
            }
            
            throw $th;
        }
    }

    private function chargeAndCreateTransaction(int $amountCents, string $paymentMethod, Customer $customer): Transaction
    {
        if ($paymentMethod === 'odessa') {
            return ($this->chargeOdessaAction)($customer->customerable, $amountCents);
        }

        return ($this->chargeStripePaymentMethodAction)($customer, $amountCents, $paymentMethod);
    }
}