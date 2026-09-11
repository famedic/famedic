<?php

namespace App\Jobs\Laboratory;

use App\Actions\Laboratories\SyncGdaResultPdfToStorageAction;
use App\Exceptions\GdaResultsNotAvailableException;
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

    public int $tries = 5;

    /**
     * TTL máximo del lock de unicidad mientras el job está en vuelo o reintentando.
     *
     * Laravel 11 libera el lock al terminar (éxito o fallo definitivo) en
     * CallQueuedHandler::ensureUniqueJobLockIsReleased(). uniqueFor NO mantiene
     * el lock una hora después de un sync exitoso: una notificación 1 hora
     * después puede despachar otro job.
     *
     * Si el job se libera para retry, el lock se conserva hasta uniqueFor o hasta
     * que el job termine. Eso evita martillar GDA con webhooks simultáneos
     * (p. ej. PCR + perfil al mismo segundo) y permite que el retry en curso
     * use latestResultsForOrder (la notificación más reciente).
     */
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
        return [60, 300, 900, 1800, 3600];
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
        } catch (GdaResultsNotAvailableException $e) {
            Log::warning('GDA results not available yet, job will retry', [
                'purchase_id' => $this->purchaseId,
                'notification_id' => $this->notificationId,
                'order_id' => $e->orderId,
                'gda_message' => $e->gdaMessage,
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries,
            ]);

            throw $e;
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
