<?php

namespace App\Actions\Transactions;

use App\Models\Customer;
use App\Models\Transaction;

class CreateCouponBalanceTransactionAction
{
    public function __invoke(Customer $customer, int $couponId, int $couponAmountCents): Transaction
    {
        return Transaction::create([
            'transaction_amount_cents' => 0,
            'payment_method' => 'coupon_balance',
            'reference_id' => 'COUPON-'.$couponId,
            'gateway' => 'coupon_balance',
            'gateway_status' => 'completed',
            'details' => [
                'description' => 'Pago con saldo a favor (cupón)',
                'coupon_id' => $couponId,
                'coupon_amount_cents' => $couponAmountCents,
                'customer_id' => $customer->id,
            ],
            'gateway_processed_at' => now(),
        ]);
    }
}
