<?php

namespace App\Actions\Transactions;

use App\Models\Customer;
use App\Models\Transaction;

class CreateCouponBalanceTransactionAction
{
    public function __invoke(
        Customer $customer,
        ?int $couponId,
        int $couponAmountCents,
        ?string $promoValidationToken = null,
    ): Transaction {
        $referenceId = $promoValidationToken !== null
            ? 'PROMO-'.substr(hash('sha256', $promoValidationToken), 0, 16)
            : 'COUPON-'.$couponId;

        $details = [
            'description' => $promoValidationToken !== null
                ? 'Pago con código promocional'
                : 'Pago con saldo a favor (cupón)',
            'coupon_amount_cents' => $couponAmountCents,
            'customer_id' => $customer->id,
        ];

        if ($couponId !== null) {
            $details['coupon_id'] = $couponId;
        }

        if ($promoValidationToken !== null) {
            $details['promo_validation_token'] = $promoValidationToken;
            $details['promo_discount_cents'] = $couponAmountCents;
        }

        return Transaction::create([
            'transaction_amount_cents' => 0,
            'payment_method' => 'coupon_balance',
            'reference_id' => $referenceId,
            'gateway' => 'coupon_balance',
            'gateway_status' => 'completed',
            'details' => $details,
            'gateway_processed_at' => now(),
        ]);
    }
}
