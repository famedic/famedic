<?php

namespace App\Actions\Laboratories;

use App\Actions\MedicalAttention\CreateRegularSubscriptionAction;
use App\Actions\MedicalAttention\NotifyMedicalMembershipPurchasedAction;
use App\Enums\LaboratoryBrand;
use App\Models\Customer;
use App\Models\MedicalAttentionSubscription;
use App\Models\Transaction;
use App\Services\LaboratoryCartMembershipService;
use Illuminate\Support\Facades\Log;

class FulfillLaboratoryCartMembershipAction
{
    public function __construct(
        private LaboratoryCartMembershipService $laboratoryCartMembershipService,
        private CreateRegularSubscriptionAction $createRegularSubscriptionAction,
        private NotifyMedicalMembershipPurchasedAction $notifyMedicalMembershipPurchasedAction,
    ) {
    }

    public function __invoke(
        Customer $customer,
        LaboratoryBrand $brand,
        Transaction $transaction,
        bool $hadMembershipInCart = false,
    ): ?MedicalAttentionSubscription {
        $shouldFulfill = $hadMembershipInCart
            || $this->laboratoryCartMembershipService->hasInCart($customer, $brand);

        if (! $shouldFulfill) {
            return null;
        }

        if ($customer->medical_attention_subscription_is_active) {
            Log::warning('Laboratory checkout: membresía en carrito pero el cliente ya tiene suscripción activa', [
                'customer_id' => $customer->id,
                'transaction_id' => $transaction->id,
            ]);
            $this->laboratoryCartMembershipService->remove($customer, $brand);

            return null;
        }

        $subscription = ($this->createRegularSubscriptionAction)($customer);

        $subscription->transactions()->attach($transaction);

        $customer->update([
            'medical_attention_subscription_expires_at' => $subscription->end_date,
        ]);

        $details = is_array($transaction->details) ? $transaction->details : [];
        $transaction->update([
            'details' => array_merge($details, [
                'has_membership_in_cart' => true,
                'membership_price_cents' => $this->laboratoryCartMembershipService->priceCents(),
                'membership_fulfilled' => true,
                'membership_subscription_id' => $subscription->id,
                'membership_fulfillment_source' => 'laboratory_checkout',
            ]),
        ]);

        $this->laboratoryCartMembershipService->remove($customer, $brand);

        Log::info('Laboratory checkout: membresía activada', [
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'transaction_id' => $transaction->id,
        ]);

        ($this->notifyMedicalMembershipPurchasedAction)(
            $subscription,
            $transaction,
            'laboratory_checkout',
        );

        return $subscription;
    }
}
