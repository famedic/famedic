<?php

namespace App\Jobs;

use App\Actions\MedicalAttention\SyncSubscriptionToMurguiaAction;
use App\Models\MedicalAttentionSubscription;
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
    ) {}

    public function handle(SyncSubscriptionToMurguiaAction $syncAction): void
    {
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
