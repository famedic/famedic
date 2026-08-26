<?php

namespace App\Jobs;

use App\Actions\MedicalAttention\SyncSubscriptionToMurguiaAction;
use App\Models\MedicalAttentionSubscription;
use App\Support\LocalExternalIntegrationGate;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncSubscriptionToMurguiaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public MedicalAttentionSubscription $subscription,
        public string $status,
        public Carbon $startDate,
        public Carbon $endDate
    ) {
        $this->onQueue(config('services.murguia.queue', 'default'));
    }

    public function handle(SyncSubscriptionToMurguiaAction $syncAction): void
    {
        if (! LocalExternalIntegrationGate::allows('murguia')) {
            Log::info('Murguia sync skipped — local real test isolation', [
                'subscription_id' => $this->subscription->id,
            ]);

            return;
        }

        $syncAction($this->subscription, $this->status, $this->startDate, $this->endDate);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Murguia sync failed permanently', [
            'subscription_id' => $this->subscription->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
