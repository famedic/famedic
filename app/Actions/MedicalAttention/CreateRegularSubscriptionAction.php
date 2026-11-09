<?php

namespace App\Actions\MedicalAttention;

use App\Enums\MedicalSubscriptionType;
use App\Jobs\SyncSubscriptionToMurguiaJob;
use App\Models\Customer;
use App\Models\MedicalAttentionSubscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateRegularSubscriptionAction
{
    public function __invoke(
        Customer $customer,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
        bool $dispatchSynchronization = true,
        ?int $settledPriceCents = null
    ): MedicalAttentionSubscription {
        return DB::transaction(function () use ($customer, $startDate, $endDate, $dispatchSynchronization, $settledPriceCents) {
            $start = $startDate ?? now();
            $end = $endDate ?? $start->copy()->addYear();
            $priceCents = $settledPriceCents ?? config('famedic.medical_attention_subscription_price_cents', 30000);
            if ($settledPriceCents !== null && $settledPriceCents <= 0) {
                throw new \DomainException('Invalid settled membership price');
            }

            $subscription = MedicalAttentionSubscription::create([
                'customer_id' => $customer->id,
                'start_date' => $start,
                'end_date' => $end,
                'price_cents' => $priceCents,
                'type' => MedicalSubscriptionType::REGULAR,
            ]);

            Log::info('Regular subscription created', [
                'subscription_id' => $subscription->id,
                'customer_id' => $customer->id,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'price_cents' => $priceCents,
            ]);

            if ($dispatchSynchronization) {
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
