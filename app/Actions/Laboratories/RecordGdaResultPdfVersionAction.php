<?php

namespace App\Actions\Laboratories;

use App\Enums\LaboratoryResultEventType;
use App\Enums\LaboratoryResultPdfClassification;
use App\Enums\LaboratoryResultStatus as LaboratoryResultStatusEnum;
use App\Jobs\ExtractLaboratoryResultReportJob;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryResultEvent;
use App\Models\LaboratoryResultStatus;
use App\Models\LaboratoryResultVersion;
use App\Services\LaboratoryResults\LaboratoryResultPdfClassifier;
use App\Services\LaboratoryResults\LaboratoryResultRefreshPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecordGdaResultPdfVersionAction
{
    public function __construct(
        private LaboratoryResultPdfClassifier $classifier,
        private LaboratoryResultRefreshPolicy $refreshPolicy,
        private AttemptReleaseLaboratoryResultsNotificationAction $attemptReleaseResultsNotificationAction,
    ) {}

    /**
     * Records the GDA PDF classification in parallel with the current production flow.
     * It deliberately does not decide email, ActiveCampaign, or patient availability.
     */
    public function execute(
        LaboratoryPurchase $purchase,
        string $pdfBinary,
        string $storagePath,
        ?LaboratoryNotification $notification = null,
        string $source = 'gda',
        bool $attemptRelease = true,
    ): void {
        $purchase->loadMissing('laboratoryPurchaseItems');

        if ($purchase->laboratoryPurchaseItems->isEmpty()) {
            Log::info('laboratory_result_classification_skipped_no_items', [
                'purchase_id' => $purchase->id,
                'notification_id' => $notification?->id,
            ]);

            return;
        }

        $sha256 = hash('sha256', $pdfBinary);
        $classification = $this->classifier->classifyBinary($pdfBinary);
        $now = now();
        $shouldAttemptRelease = false;
        $versionIdsForStructuredExtraction = [];

        DB::transaction(function () use ($purchase, $notification, $storagePath, $source, $sha256, $classification, $now, &$shouldAttemptRelease, &$versionIdsForStructuredExtraction): void {
            /** @var LaboratoryPurchaseItem $item */
            foreach ($purchase->laboratoryPurchaseItems as $item) {
                $status = $this->resolveStatus($purchase, $item, $now);
                $previousLatestHash = $status->versions()->latest('id')->value('sha256');

                $version = LaboratoryResultVersion::query()->firstOrCreate(
                    [
                        'laboratory_result_status_id' => $status->id,
                        'sha256' => $sha256,
                    ],
                    [
                        'laboratory_notification_id' => $notification?->id,
                        'storage_path' => $storagePath,
                        'source' => $source,
                        'classification' => $classification->classification,
                        'classification_reason' => $classification->reason,
                        'matched_rule' => $classification->matchedRule,
                        'classifier' => LaboratoryResultPdfClassifier::CLASSIFIER_NAME,
                        'classified_at' => $now,
                        'pdf_available_at' => $notification?->results_received_at ?? $now,
                    ]
                );

                $createdVersion = $version->wasRecentlyCreated;

                if ($createdVersion) {
                    $this->recordEvent(
                        $status,
                        $version,
                        $previousLatestHash === null
                            ? LaboratoryResultEventType::PdfFetched
                            : LaboratoryResultEventType::PdfChanged,
                        metadata: [
                            'sha256_short' => substr($sha256, 0, 12),
                            'source' => $source,
                        ],
                    );
                }

                if (! $createdVersion) {
                    $this->touchCheckedAt($status, $now);

                    continue;
                }

                $targetStatus = $this->statusForClassification($classification->classification);
                $fromStatus = $status->status;

                $updates = [
                    'last_checked_at' => $now,
                    'status' => $targetStatus,
                ];

                if ($targetStatus === LaboratoryResultStatusEnum::Complete && $status->interpreted_at === null) {
                    $updates['interpreted_at'] = $now;
                }

                $status->fill($updates);
                $status->save();

                $this->recordEvent(
                    $status,
                    $version,
                    LaboratoryResultEventType::Classified,
                    fromStatus: $fromStatus,
                    toStatus: $targetStatus,
                    metadata: [
                        'classification' => $classification->classification->value,
                        'reason' => $classification->reason,
                        'matched_rule' => $classification->matchedRule,
                        'sha256_short' => substr($sha256, 0, 12),
                    ],
                );

                $this->recordStatusSpecificEvent($status, $version, $fromStatus, $targetStatus);

                if ($targetStatus === LaboratoryResultStatusEnum::PendingInterpretation) {
                    $this->refreshPolicy->scheduleInitialPendingIfNeeded($status, $version);
                } elseif (in_array($targetStatus, [
                    LaboratoryResultStatusEnum::Complete,
                    LaboratoryResultStatusEnum::ManualReview,
                    LaboratoryResultStatusEnum::Error,
                ], true)) {
                    $this->refreshPolicy->markTerminal($status, $version, reason: 'classified_'.$targetStatus->value);
                }

                if ($targetStatus === LaboratoryResultStatusEnum::Complete) {
                    $shouldAttemptRelease = true;
                }

                if ($createdVersion && $targetStatus === LaboratoryResultStatusEnum::Complete) {
                    $versionIdsForStructuredExtraction[] = $version->id;
                }

                Log::info('laboratory_result_classified', [
                    'purchase_id' => $purchase->id,
                    'purchase_item_id' => $item->id,
                    'result_status_id' => $status->id,
                    'result_version_id' => $version->id,
                    'sha256_short' => substr($sha256, 0, 12),
                    'classification' => $classification->classification->value,
                    'reason' => $classification->reason,
                ]);
            }
        });

        if ($shouldAttemptRelease && $attemptRelease) {
            $this->attemptReleaseResultsNotificationAction->execute($purchase->fresh(), source: 'result_status_complete');
        }

        if (config('laboratory-results.structured_extraction.enabled', false)) {
            foreach (array_unique($versionIdsForStructuredExtraction) as $versionId) {
                ExtractLaboratoryResultReportJob::dispatch($versionId);
            }
        }
    }

    private function resolveStatus(
        LaboratoryPurchase $purchase,
        LaboratoryPurchaseItem $item,
        mixed $now,
    ): LaboratoryResultStatus {
        $status = LaboratoryResultStatus::query()
            ->where('laboratory_purchase_id', $purchase->id)
            ->where('laboratory_purchase_item_id', $item->id)
            ->lockForUpdate()
            ->first();

        if ($status) {
            if ($status->first_available_at === null) {
                $status->first_available_at = $now;
                $status->save();
            }

            return $status;
        }

        return LaboratoryResultStatus::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'laboratory_purchase_item_id' => $item->id,
            'status' => LaboratoryResultStatusEnum::AvailableUnchecked,
            'first_available_at' => $now,
            'last_checked_at' => $now,
        ]);
    }

    private function touchCheckedAt(LaboratoryResultStatus $status, mixed $now): void
    {
        $status->forceFill(['last_checked_at' => $now])->save();
    }

    private function statusForClassification(
        LaboratoryResultPdfClassification $classification
    ): LaboratoryResultStatusEnum {
        return match ($classification) {
            LaboratoryResultPdfClassification::PendingInterpretation => LaboratoryResultStatusEnum::PendingInterpretation,
            LaboratoryResultPdfClassification::Complete => LaboratoryResultStatusEnum::Complete,
            LaboratoryResultPdfClassification::Unknown => LaboratoryResultStatusEnum::ManualReview,
        };
    }

    private function recordStatusSpecificEvent(
        LaboratoryResultStatus $status,
        LaboratoryResultVersion $version,
        LaboratoryResultStatusEnum $fromStatus,
        LaboratoryResultStatusEnum $toStatus,
    ): void {
        $eventType = match ($toStatus) {
            LaboratoryResultStatusEnum::PendingInterpretation => LaboratoryResultEventType::InterpretationPending,
            LaboratoryResultStatusEnum::Complete => LaboratoryResultEventType::ResultComplete,
            LaboratoryResultStatusEnum::ManualReview => LaboratoryResultEventType::ClassificationFailed,
            default => null,
        };

        if ($eventType === null || $fromStatus === $toStatus) {
            return;
        }

        $this->recordEvent(
            $status,
            $version,
            $eventType,
            fromStatus: $fromStatus,
            toStatus: $toStatus,
        );
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function recordEvent(
        LaboratoryResultStatus $status,
        ?LaboratoryResultVersion $version,
        LaboratoryResultEventType $eventType,
        ?LaboratoryResultStatusEnum $fromStatus = null,
        ?LaboratoryResultStatusEnum $toStatus = null,
        ?array $metadata = null,
    ): void {
        LaboratoryResultEvent::query()->create([
            'laboratory_result_status_id' => $status->id,
            'laboratory_result_version_id' => $version?->id,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
