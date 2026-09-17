<?php

namespace App\Actions\Laboratories;

use App\Enums\LaboratoryResultEventType;
use App\Enums\LaboratoryResultStatus as LaboratoryResultStatusEnum;
use App\Exceptions\GdaResultsNotAvailableException;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryResultStatus;
use App\Services\LaboratoryResults\LaboratoryResultRefreshPolicy;
use App\Support\GDA\GdaPayloadSanitizer;
use App\Support\Laboratory\GdaResultsPdfStatus;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshGdaLaboratoryResultAction
{
    public function __construct(
        private SyncGdaResultPdfToStorageAction $syncGdaResultPdfToStorageAction,
        private StoreGdaResultsPdfToStorageAction $storeGdaResultsPdfToStorageAction,
        private LaboratoryResultRefreshPolicy $refreshPolicy,
    ) {}

    public function execute(int $resultStatusId): void
    {
        if (! $this->refreshPolicy->enabled()) {
            Log::info('laboratory_result_refresh_skipped_disabled', [
                'result_status_id' => $resultStatusId,
            ]);

            return;
        }

        $lock = Cache::lock(
            $this->lockKey($resultStatusId),
            (int) config('services.gda.result_refresh.lock_seconds', 600)
        );

        if (! $lock->get()) {
            Log::info('laboratory_result_refresh_skipped_locked', [
                'result_status_id' => $resultStatusId,
            ]);

            return;
        }

        try {
            $this->refreshWithLock($resultStatusId);
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * @return array{ok: bool, code: string, message: string, result_status_id: int, status?: ?string, changed?: bool}
     */
    public function executeForAdmin(int $resultStatusId): array
    {
        $lock = Cache::lock(
            $this->lockKey($resultStatusId),
            (int) config('services.gda.result_refresh.lock_seconds', 600)
        );

        if (! $lock->get()) {
            return [
                'ok' => false,
                'code' => 'refresh_locked',
                'message' => 'Ya hay una actualización en proceso para este estudio.',
                'result_status_id' => $resultStatusId,
            ];
        }

        try {
            $status = $this->loadAdminRefreshableStatus($resultStatusId);

            if (! $status) {
                return [
                    'ok' => false,
                    'code' => 'refresh_not_due',
                    'message' => 'Este estudio no está disponible para actualización desde GDA en este momento.',
                    'result_status_id' => $resultStatusId,
                ];
            }

            $previousVersionId = $status->versions()->latest('id')->value('id');

            $this->refreshLoadedStatus($status);

            $fresh = $status->fresh(['versions']);
            $latestVersionId = $fresh?->versions()->latest('id')->value('id');

            return [
                'ok' => true,
                'code' => 'refresh_processed',
                'message' => 'Actualización desde GDA procesada.',
                'result_status_id' => $resultStatusId,
                'status' => $fresh?->status?->value,
                'changed' => $latestVersionId !== $previousVersionId,
            ];
        } finally {
            optional($lock)->release();
        }
    }

    private function refreshWithLock(int $resultStatusId): void
    {
        $status = $this->loadRefreshableStatus($resultStatusId);

        if (! $status) {
            return;
        }

        $this->refreshLoadedStatus($status);
    }

    private function refreshLoadedStatus(LaboratoryResultStatus $status): void
    {
        $purchase = $status->laboratoryPurchase;

        if (! $purchase) {
            $this->markPermanentFailure($status, 'purchase_not_found');

            return;
        }

        $purchase->refresh();

        if ($purchase->isManualResults()) {
            Log::info('laboratory_result_refresh_skipped_manual_result', [
                'result_status_id' => $status->id,
                'purchase_id' => $purchase->id,
                'purchase_item_id' => $status->laboratory_purchase_item_id,
            ]);

            $status->forceFill([
                'next_check_at' => null,
                'last_checked_at' => now(),
            ])->save();

            return;
        }

        $notification = $this->latestResultsNotification($status);

        if (! $notification) {
            $this->markPermanentFailure($status, 'results_notification_not_found');

            return;
        }

        Log::info('laboratory_result_refresh_started', [
            'result_status_id' => $status->id,
            'purchase_id' => $purchase->id,
            'purchase_item_id' => $status->laboratory_purchase_item_id,
            'attempt' => $status->check_attempts + 1,
        ]);

        try {
            $base64 = $this->syncGdaResultPdfToStorageAction->fetchPdfBase64($notification);
            $pdfBinary = $this->decodePdf($base64);
        } catch (GdaResultsNotAvailableException $exception) {
            $this->markTemporaryFailure($status, 'gda_not_available', [
                'order_id' => $exception->orderId,
            ]);

            return;
        } catch (DomainException $exception) {
            $this->markPermanentFailure($status, 'invalid_pdf_response', $exception);

            return;
        } catch (Throwable $exception) {
            $this->markTemporaryFailure($status, 'gda_error', [
                'exception' => $exception::class,
            ]);

            return;
        }

        $sha256 = hash('sha256', $pdfBinary);
        $latestVersion = $status->versions()->latest('id')->first();

        if ($latestVersion && hash_equals($latestVersion->sha256, $sha256)) {
            $this->refreshPolicy->recordPendingRefreshAttempt($status, $latestVersion, reason: 'pdf_unchanged');

            Log::info('laboratory_result_refresh_unchanged', [
                'result_status_id' => $status->id,
                'purchase_id' => $purchase->id,
                'purchase_item_id' => $status->laboratory_purchase_item_id,
                'sha256_short' => substr($sha256, 0, 12),
            ]);

            return;
        }

        $path = $this->storeGdaResultsPdfToStorageAction->execute(
            $purchase,
            base64_encode($pdfBinary),
            $notification,
            overwrite: GdaResultsPdfStatus::isGdaManagedPath($purchase->results),
            preserveExisting: true,
        );

        $status->refresh();
        $newLatestVersion = $status->versions()->latest('id')->first();

        if ($status->status === LaboratoryResultStatusEnum::PendingInterpretation) {
            $this->refreshPolicy->recordPendingRefreshAttempt($status, $newLatestVersion, reason: 'pdf_changed_pending');
        } elseif (in_array($status->status, [
            LaboratoryResultStatusEnum::Complete,
            LaboratoryResultStatusEnum::ManualReview,
            LaboratoryResultStatusEnum::Error,
        ], true)) {
            $this->refreshPolicy->markTerminal($status, $newLatestVersion, reason: 'pdf_changed_'.$status->status->value);
        }

        Log::info('laboratory_result_refresh_changed', [
            'result_status_id' => $status->id,
            'purchase_id' => $purchase->id,
            'purchase_item_id' => $status->laboratory_purchase_item_id,
            'sha256_short' => substr($sha256, 0, 12),
            'path' => $path,
            'status' => $status->fresh()->status?->value,
        ]);
    }

    private function loadRefreshableStatus(int $resultStatusId): ?LaboratoryResultStatus
    {
        return DB::transaction(function () use ($resultStatusId) {
            $status = LaboratoryResultStatus::query()
                ->with(['laboratoryPurchase', 'laboratoryPurchaseItem'])
                ->whereKey($resultStatusId)
                ->lockForUpdate()
                ->first();

            if (! $status) {
                Log::info('laboratory_result_refresh_skipped_missing_status', [
                    'result_status_id' => $resultStatusId,
                ]);

                return null;
            }

            if ($status->status !== LaboratoryResultStatusEnum::PendingInterpretation) {
                Log::info('laboratory_result_refresh_skipped_terminal_status', [
                    'result_status_id' => $status->id,
                    'status' => $status->status?->value,
                ]);

                return null;
            }

            if ($status->next_check_at === null || $status->next_check_at->isFuture()) {
                Log::info('laboratory_result_refresh_skipped_not_due', [
                    'result_status_id' => $status->id,
                    'next_check_at' => $status->next_check_at?->toIso8601String(),
                ]);

                return null;
            }

            if ($status->check_attempts >= $this->refreshPolicy->maxAttempts()) {
                $this->refreshPolicy->recordPendingRefreshAttempt($status, reason: 'refresh_attempts_exhausted');

                return null;
            }

            return $status;
        });
    }

    private function loadAdminRefreshableStatus(int $resultStatusId): ?LaboratoryResultStatus
    {
        return DB::transaction(function () use ($resultStatusId) {
            $status = LaboratoryResultStatus::query()
                ->with(['laboratoryPurchase', 'laboratoryPurchaseItem'])
                ->whereKey($resultStatusId)
                ->lockForUpdate()
                ->first();

            if (! $status) {
                return null;
            }

            if (in_array($status->status, [
                LaboratoryResultStatusEnum::Complete,
                LaboratoryResultStatusEnum::ManualReview,
                LaboratoryResultStatusEnum::Error,
            ], true)) {
                return null;
            }

            if ($status->next_check_at !== null && $status->next_check_at->isFuture()) {
                return null;
            }

            if ($status->check_attempts >= $this->refreshPolicy->maxAttempts()) {
                return null;
            }

            return $status;
        });
    }

    private function latestResultsNotification(LaboratoryResultStatus $status): ?LaboratoryNotification
    {
        $purchase = $status->laboratoryPurchase;

        if (! $purchase) {
            return null;
        }

        return LaboratoryNotification::latestResultsForOrder(
            $purchase->id,
            $purchase->gda_order_id,
            $purchase->gda_consecutivo
        );
    }

    private function decodePdf(string $base64): string
    {
        $normalizedBase64 = GdaPayloadSanitizer::stripDataUriPrefix(trim($base64));
        $pdfBinary = base64_decode($normalizedBase64, true);

        if ($pdfBinary === false) {
            throw new DomainException('GDA results PDF base64 is invalid.');
        }

        if (! str_starts_with($pdfBinary, '%PDF')) {
            throw new DomainException('GDA results payload is not a valid PDF.');
        }

        return $pdfBinary;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function markTemporaryFailure(LaboratoryResultStatus $status, string $reason, array $metadata = []): void
    {
        $latestVersion = $status->versions()->latest('id')->first();

        $this->refreshPolicy->recordPendingRefreshAttempt($status, $latestVersion, reason: $reason);
        $this->refreshPolicy->recordEvent(
            $status->fresh(),
            $latestVersion,
            LaboratoryResultEventType::RefreshFailed,
            metadata: [
                'reason' => $reason,
            ] + $metadata,
        );

        Log::warning('laboratory_result_refresh_failed', [
            'result_status_id' => $status->id,
            'purchase_id' => $status->laboratory_purchase_id,
            'purchase_item_id' => $status->laboratory_purchase_item_id,
            'reason' => $reason,
        ] + $metadata);
    }

    private function markPermanentFailure(
        LaboratoryResultStatus $status,
        string $reason,
        ?Throwable $exception = null,
    ): void {
        $latestVersion = $status->versions()->latest('id')->first();
        $fromStatus = $status->status;

        $status->forceFill([
            'status' => LaboratoryResultStatusEnum::ManualReview,
            'last_checked_at' => now(),
            'next_check_at' => null,
        ])->save();

        $this->refreshPolicy->recordEvent(
            $status,
            $latestVersion,
            LaboratoryResultEventType::RefreshFailed,
            fromStatus: $fromStatus,
            toStatus: LaboratoryResultStatusEnum::ManualReview,
            metadata: [
                'reason' => $reason,
                'exception' => $exception ? $exception::class : null,
            ],
        );

        Log::warning('laboratory_result_refresh_failed', [
            'result_status_id' => $status->id,
            'purchase_id' => $status->laboratory_purchase_id,
            'purchase_item_id' => $status->laboratory_purchase_item_id,
            'reason' => $reason,
            'terminal' => true,
            'exception' => $exception ? $exception::class : null,
        ]);
    }

    private function lockKey(int $resultStatusId): string
    {
        return 'laboratory-result-refresh:'.$resultStatusId;
    }
}
