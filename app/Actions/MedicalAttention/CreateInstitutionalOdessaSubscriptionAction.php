<?php

namespace App\Actions\MedicalAttention;

use App\Enums\MedicalSubscriptionType;
use App\Jobs\SyncSubscriptionToMurguiaJob;
use App\Models\Customer;
use App\Models\MedicalAttentionSubscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Crea suscripción INSTITUTIONAL para afiliados Odessa y sincroniza con Murguía (SyncSubscriptionToMurguiaJob en la misma petición).
 */
class CreateInstitutionalOdessaSubscriptionAction
{
    public function __invoke(
        Customer $customer,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null
    ): MedicalAttentionSubscription {
        return DB::transaction(function () use ($customer, $startDate, $endDate) {
            $start = $startDate ?? now();
            $years = max(1, (int) config('famedic.institutional_odessa_subscription_years', 1));
            $end = $endDate ?? $start->copy()->addYears($years);
            $priceCents = (int) config('famedic.institutional_odessa_subscription_price_cents', 0);

            $subscription = MedicalAttentionSubscription::create([
                'customer_id' => $customer->id,
                'start_date' => $start,
                'end_date' => $end,
                'price_cents' => $priceCents,
                'type' => MedicalSubscriptionType::INSTITUTIONAL,
            ]);

            Log::info('Institutional Odessa subscription created', [
                'subscription_id' => $subscription->id,
                'customer_id' => $customer->id,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'price_cents' => $priceCents,
            ]);

            $customer->update(['medical_attention_subscription_expires_at' => $subscription->end_date]);

            SyncSubscriptionToMurguiaJob::dispatchSync(
                $subscription,
                'activo',
                $subscription->start_date,
                $subscription->end_date
            );

            return $subscription;
        });
    }
}
