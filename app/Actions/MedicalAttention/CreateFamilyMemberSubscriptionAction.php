<?php

namespace App\Actions\MedicalAttention;

use App\Enums\MedicalSubscriptionType;
use App\Jobs\SyncSubscriptionToMurguiaJob;
use App\Models\Customer;
use App\Models\MedicalAttentionSubscription;
use Illuminate\Support\Facades\DB;

class CreateFamilyMemberSubscriptionAction
{
    public function __invoke(
        Customer $customer,
        MedicalAttentionSubscription $parentSubscription
    ): MedicalAttentionSubscription {
        return DB::transaction(function () use ($customer, $parentSubscription) {
            $subscription = MedicalAttentionSubscription::create([
                'customer_id' => $customer->id,
                'start_date' => $parentSubscription->start_date,
                'end_date' => $parentSubscription->end_date,
                'price_cents' => 0, // Covered by parent subscription
                'type' => MedicalSubscriptionType::FAMILY_MEMBER,
                'parent_subscription_id' => $parentSubscription->id,
            ]);

            // Only sync with Murguia if parent subscription is currently active
            if ($parentSubscription->is_active) {
                SyncSubscriptionToMurguiaJob::dispatch(
                    $subscription,
                    'activo',
                    $subscription->start_date,
                    $subscription->end_date
                );
            }

            return $subscription;
        });
    }
}
