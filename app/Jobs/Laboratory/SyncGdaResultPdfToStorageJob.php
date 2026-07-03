<?php

namespace App\Jobs\Laboratory;

use App\Actions\Laboratories\SyncGdaResultPdfToStorageAction;
use DomainException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncGdaResultPdfToStorageJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $purchaseId,
        public ?int $notificationId = null,
    ) {
        $this->onQueue(config('services.gda.results_sync_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return 'gda-results-pdf:'.$this->purchaseId;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(SyncGdaResultPdfToStorageAction $syncGdaResultPdfToStorageAction): void
    {
        Log::info('GDA results PDF sync job started', [
            'purchase_id' => $this->purchaseId,
            'notification_id' => $this->notificationId,
            'attempt' => $this->attempts(),
        ]);

        try {
            $path = $syncGdaResultPdfToStorageAction->execute($this->purchaseId, $this->notificationId);

            if ($path === null) {
                Log::info('GDA results PDF sync job finished without storing PDF', [
                    'purchase_id' => $this->purchaseId,
                    'notification_id' => $this->notificationId,
                ]);

                return;
            }

            Log::info('GDA results PDF sync job completed', [
                'purchase_id' => $this->purchaseId,
                'notification_id' => $this->notificationId,
                'path' => $path,
            ]);
        } catch (DomainException $e) {
            Log::error('GDA results PDF sync job failed (non-retryable)', [
                'purchase_id' => $this->purchaseId,
                'notification_id' => $this->notificationId,
                'error' => $e->getMessage(),
            ]);

            $this->fail($e);
        }
    }
}
