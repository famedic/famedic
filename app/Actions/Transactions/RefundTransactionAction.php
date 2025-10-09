<?php

namespace App\Actions\Transactions;

use App\Models\Transaction;
use App\Notifications\OdessaPaymentRefunded;
use Illuminate\Support\Facades\Notification;

class RefundTransactionAction
{
    public function __invoke(Transaction $transaction)
    {
        try {
            if ($transaction->payment_method == 'odessa') {
                $this->refundOdessaTransaction($transaction);
            } else {
                $this->refundStripeTransaction($transaction);
            }


            $transaction->delete();
        } catch (\Exception $e) {
            throw $e;
        }
    }

    private function refundOdessaTransaction(Transaction $transaction)
    {
        if (config('services.odessa.refund_report_emails')) {
            $customer = $this->getCustomerFromTransaction($transaction);

            // Ensure we have an OdessaAfiliateAccount
            if (!$customer->customerable instanceof \App\Models\OdessaAfiliateAccount) {
                throw new \Exception('Transaction is marked as Odessa but customer does not have OdessaAfiliateAccount');
            }

            Notification::route('mail', config('services.odessa.refund_report_emails'))
                ->notify(
                    new OdessaPaymentRefunded(
                        $transaction->reference_id,
                        $transaction->formatted_amount,
                        $customer->customerable
                    )
                );
        }
    }

    private function refundStripeTransaction(Transaction $transaction)
    {
        $customer = $this->getCustomerFromTransaction($transaction);

        $customer->refund($transaction->reference_id);
    }

    private function getCustomerFromTransaction(Transaction $transaction)
    {
        // Check if transaction is attached to laboratory purchases
        if ($transaction->laboratoryPurchases()->exists()) {
            return $transaction->laboratoryPurchases()->first()->customer;
        }

        // Check if transaction is attached to online pharmacy purchases
        if ($transaction->onlinePharmacyPurchases()->exists()) {
            return $transaction->onlinePharmacyPurchases()->first()->customer;
        }

        // Check if transaction is attached to medical attention subscriptions
        if ($transaction->medicalAttentionSubscriptions()->exists()) {
            return $transaction->medicalAttentionSubscriptions()->first()->customer;
        }

        throw new \Exception('No customer found for transaction: ' . $transaction->id);
    }
}
