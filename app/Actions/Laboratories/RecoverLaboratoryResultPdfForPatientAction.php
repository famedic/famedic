<?php

namespace App\Actions\Laboratories;

use App\Exceptions\LaboratoryResultsRecoveryInProgressException;
use App\Exceptions\LaboratoryResultsRecoveryUnavailableException;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Services\LaboratoryResults\LaboratoryPurchaseResultCompletionService;
use App\Support\Laboratory\GdaResultsPdfStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class RecoverLaboratoryResultPdfForPatientAction
{
    public function __construct(
        private SyncGdaResultPdfToStorageAction $syncGdaResultPdfToStorageAction,
        private StoreGdaResultsPdfToStorageAction $storeGdaResultsPdfToStorageAction,
        private LaboratoryPurchaseResultCompletionService $completionService,
    ) {}

    /**
     * Recupera un PDF histórico desde GDA sin relajar autorización: el controller
     * debe llamar esta acción únicamente después de autorizar la compra.
     *
     * @return array{path: string, recovered: bool, fallback: bool, correlation_id: string}
     */
    public function execute(LaboratoryPurchase $purchase, ?string $correlationId = null): array
    {
        $correlationId ??= (string) Str::uuid();
        $startedAt = microtime(true);
        $lockSeconds = max(1, (int) config('laboratory-results.recovery.lock_seconds', 120));
        $lock = Cache::lock("laboratory-results-recovery:{$purchase->id}", $lockSeconds);

        $this->log('requested', $purchase, $correlationId);

        if (! $lock->get()) {
            $this->log('lock_contended', $purchase, $correlationId);

            throw new LaboratoryResultsRecoveryInProgressException;
        }

        $this->log('lock_acquired', $purchase, $correlationId);

        try {
            $purchase->refresh();
            $assessment = GdaResultsPdfStatus::assessPurchase($purchase);

            if ($assessment->isManual()) {
                return $this->serveExistingPath($purchase, $correlationId, $startedAt, recovered: false);
            }

            if ($assessment->isGdaCurrent() && $this->currentStoredGdaResultIsServable($purchase)) {
                return $this->serveExistingPath($purchase, $correlationId, $startedAt, recovered: false);
            }

            $notification = LaboratoryNotification::latestResultsForOrder(
                $purchase->id,
                $purchase->gda_order_id,
                $purchase->gda_consecutivo
            );

            if (! $notification) {
                return $this->fallbackOrFail(
                    $purchase,
                    $correlationId,
                    $startedAt,
                    'notification_missing'
                );
            }

            try {
                $this->log('gda_started', $purchase, $correlationId, [
                    'notification_id' => $notification->id,
                ]);

                $pdfBase64 = $this->syncGdaResultPdfToStorageAction->fetchPdfBase64($notification);

                $this->log('gda_received', $purchase, $correlationId, [
                    'notification_id' => $notification->id,
                ]);

                $path = $this->storeGdaResultsPdfToStorageAction->execute(
                    $purchase,
                    $pdfBase64,
                    $notification,
                    overwrite: $assessment->isGdaManaged,
                    preserveExisting: true,
                    updatePurchaseResults: false,
                    strictClassification: true,
                );

                $this->logStoredPdf($purchase, $notification, $path, $correlationId);

                $purchase->refresh();
                $completion = $this->completionService->evaluate($purchase);
                $latestVersion = $purchase->laboratoryResultStatuses()
                    ->with('versions')
                    ->get()
                    ->flatMap(fn ($status) => $status->versions)
                    ->sortByDesc('id')
                    ->first();

                $this->log('completion_classified', $purchase, $correlationId, [
                    'notification_id' => $notification->id,
                    'classification' => $latestVersion?->classification?->value,
                ] + $completion->toLogContext());

                $this->log('s3_started', $purchase, $correlationId, [
                    'notification_id' => $notification->id,
                ]);

                if (! Storage::exists($path)) {
                    throw new LaboratoryResultsRecoveryUnavailableException(
                        'storage_missing_after_write',
                        'Recovered PDF was not found in storage after write.'
                    );
                }

                if (! $completion->isComplete && ! $completion->legacyFallback && ! $this->hasReviewablePdf($completion)) {
                    throw new LaboratoryResultsRecoveryUnavailableException(
                        'result_incomplete',
                        'Recovered result is not complete.'
                    );
                }

                $purchase->forceFill(['results' => $path])->save();
                $purchase->refresh();

                if (! $completion->isComplete && ! $completion->legacyFallback) {
                    $this->log('semantic_incomplete_pdf_available', $purchase, $correlationId, [
                        'notification_id' => $notification->id,
                        'storage_path' => $path,
                    ] + $completion->toLogContext());
                }

                $this->log('s3_completed', $purchase, $correlationId, [
                    'notification_id' => $notification->id,
                    'storage_path' => $path,
                ]);

                return $this->serveExistingPath($purchase, $correlationId, $startedAt, recovered: true);
            } catch (LaboratoryResultsRecoveryUnavailableException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                $this->log('failed', $purchase, $correlationId, [
                    'error_code' => 'gda_or_storage_error',
                    'exception_class' => $exception::class,
                    'error' => $exception->getMessage(),
                ]);

                return $this->fallbackOrFail(
                    $purchase,
                    $correlationId,
                    $startedAt,
                    'gda_or_storage_error'
                );
            }
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * @return array{path: string, recovered: bool, fallback: bool, correlation_id: string}
     */
    private function serveExistingPath(
        LaboratoryPurchase $purchase,
        string $correlationId,
        float $startedAt,
        bool $recovered,
    ): array {
        $purchase->refresh();

        if (empty($purchase->results) || ! Storage::exists($purchase->results)) {
            throw new LaboratoryResultsRecoveryUnavailableException(
                'storage_missing',
                'No stored PDF is available.'
            );
        }

        $this->log('existing_path_ready', $purchase, $correlationId, [
            'duration_ms' => $this->durationMs($startedAt),
            'storage_path' => $purchase->results,
            'recovered' => $recovered,
            'fallback' => false,
        ]);

        return [
            'path' => $purchase->results,
            'recovered' => $recovered,
            'fallback' => false,
            'correlation_id' => $correlationId,
        ];
    }

    /**
     * @return array{path: string, recovered: bool, fallback: bool, correlation_id: string}
     */
    private function fallbackOrFail(
        LaboratoryPurchase $purchase,
        string $correlationId,
        float $startedAt,
        string $errorCode,
    ): array {
        $purchase->refresh();

        if (! empty($purchase->results) && Storage::exists($purchase->results)) {
            $this->log('s3_fallback', $purchase, $correlationId, [
                'duration_ms' => $this->durationMs($startedAt),
                'error_code' => $errorCode,
                'storage_path' => $purchase->results,
            ]);

            return [
                'path' => $purchase->results,
                'recovered' => false,
                'fallback' => true,
                'correlation_id' => $correlationId,
            ];
        }

        $this->log('failed', $purchase, $correlationId, [
            'duration_ms' => $this->durationMs($startedAt),
            'error_code' => $errorCode,
        ]);

        throw new LaboratoryResultsRecoveryUnavailableException($errorCode);
    }

    private function currentStoredGdaResultIsServable(LaboratoryPurchase $purchase): bool
    {
        $completion = $this->completionService->evaluate($purchase);

        return ($completion->isComplete || $this->hasReviewablePdf($completion)) && ! $completion->legacyFallback;
    }

    private function hasReviewablePdf(object $completion): bool
    {
        return ($completion->manualReview ?? 0) > 0 || ($completion->error ?? 0) > 0;
    }

    private function logStoredPdf(
        LaboratoryPurchase $purchase,
        LaboratoryNotification $notification,
        string $path,
        string $correlationId,
    ): void {
        $binary = Storage::get($path);

        $this->log('pdf_validated', $purchase, $correlationId, [
            'notification_id' => $notification->id,
            'storage_path' => $path,
            'pdf_size_bytes' => is_string($binary) ? strlen($binary) : null,
            'pdf_sha256_short' => is_string($binary) ? substr(hash('sha256', $binary), 0, 12) : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $event, LaboratoryPurchase $purchase, string $correlationId, array $context = []): void
    {
        Log::info('laboratory_results_recovery.'.$event, array_filter([
            'purchase_id' => $purchase->id,
            'gda_order_id' => $purchase->gda_order_id,
            'gda_consecutivo' => $purchase->gda_consecutivo,
            'brand' => $purchase->brand?->value,
            'correlation_id' => $correlationId,
            'storage_disk' => config('filesystems.default'),
        ] + $context, fn ($value) => $value !== null));
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
