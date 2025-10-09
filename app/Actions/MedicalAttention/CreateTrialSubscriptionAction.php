<?php

namespace App\Actions\MedicalAttention;

use App\Enums\MedicalSubscriptionType;
use App\Jobs\SyncSubscriptionToMurguiaJob;
use App\Models\Customer;
use App\Models\MedicalAttentionSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateTrialSubscriptionAction
{

    public function __invoke(Customer $customer): MedicalAttentionSubscription
    {
        return DB::transaction(function () use ($customer) {
            $trialDays = config('famedic.free_medical_attention_subscription_days', 30);

            $subscription = MedicalAttentionSubscription::create([
                'customer_id' => $customer->id,
                'start_date' => now(),
                'end_date' => now()->addDays($trialDays),
                'price_cents' => 0,
                'type' => MedicalSubscriptionType::TRIAL,
            ]);

            Log::info('Trial subscription created', [
                'subscription_id' => $subscription->id,
                'customer_id' => $customer->id,
                'trial_days' => $trialDays,
            ]);

            $customer->update(['medical_attention_subscription_expires_at' => $subscription->end_date]);

            SyncSubscriptionToMurguiaJob::dispatch(
                $subscription,
                'activo',
                $subscription->start_date,
                $subscription->end_date
            );

            return $subscription;
        });
    }
}
