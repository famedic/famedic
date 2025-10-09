<?php

use App\Jobs\SyncSubscriptionToMurguiaJob;
use App\Models\MedicalAttentionSubscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Log::info('Queueing active subscriptions for Murguia sync...');

        $activeSubscriptions = MedicalAttentionSubscription::active()->with('customer')->get();

        foreach ($activeSubscriptions as $subscription) {
            SyncSubscriptionToMurguiaJob::dispatch(
                $subscription,
                'activo',
                $subscription->start_date,
                $subscription->end_date
            );
        }

        Log::info('Murguia sync migration completed', [
            'subscriptions_queued' => $activeSubscriptions->count(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This is a one-time data operation, no rollback needed
        Log::info('No rollback needed for subscription queueing migration');
    }
};
