<?php

namespace App\Actions\Admin\VendorPayments;

use Illuminate\Database\Eloquent\Collection;

class BuildVendorPaymentDetailsAction
{
    public function __invoke(Collection $selectedPurchases): array
    {
        $subtotalCents = $selectedPurchases->sum('total_cents');
        $commissionCents = 0;

        $selectedPurchases->each(function ($purchase) use (&$commissionCents) {
            $transaction = $purchase->transactions->first();
            $purchaseCommissionCents = 0;

            if ($transaction) {
                $purchaseCommissionCents = $transaction->commission_cents;
                $commissionCents += $purchaseCommissionCents;
            }

            $totalAfterCommissionCents = $purchase->total_cents - $purchaseCommissionCents;
            $purchase->formatted_total_after_commission = formattedCentsPrice($totalAfterCommissionCents);
        });

        $totalCents = $subtotalCents - $commissionCents;

        return [
            'selectedPurchases' => $selectedPurchases,
            'formattedSubtotal' => formattedCentsPrice($subtotalCents),
            'formattedCommission' => formattedCentsPrice($commissionCents),
            'formattedTotal' => formattedCentsPrice($totalCents),
        ];
    }
}
