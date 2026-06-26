<?php

namespace App\Actions\MedicalAttention;

use App\Models\MedicalAttentionSubscription;
use App\Models\Transaction;
use App\Notifications\MedicalMembershipPurchased;
use Illuminate\Support\Facades\Log;

class NotifyMedicalMembershipPurchasedAction
{
    public function __invoke(
        MedicalAttentionSubscription $subscription,
        ?Transaction $transaction = null,
        string $purchaseSource = 'medical_attention_checkout',
    ): void {
        $subscription->loadMissing('customer.user');
        $user = $subscription->customer?->user;

        if (! $user) {
            Log::warning('Medical membership purchased email skipped: user not found', [
                'subscription_id' => $subscription->id,
                'customer_id' => $subscription->customer_id,
            ]);

            return;
        }

        if (! $transaction && $subscription->transactions()->exists()) {
            $transaction = $subscription->transactions()->latest()->first();
        }

        try {
            $user->notify(new MedicalMembershipPurchased(
                $subscription,
                $transaction,
                $purchaseSource,
            ));
        } catch (\Throwable $exception) {
            Log::error('Medical membership purchased email failed', [
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
                'purchase_source' => $purchaseSource,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
