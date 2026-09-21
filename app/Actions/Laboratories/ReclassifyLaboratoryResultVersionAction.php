<?php

namespace App\Actions\Laboratories;

use App\Enums\LaboratoryResultEventType;
use App\Enums\LaboratoryResultPdfClassification;
use App\Enums\LaboratoryResultStatus as LaboratoryResultStatusEnum;
use App\Jobs\ExtractLaboratoryResultReportJob;
use App\Models\LaboratoryResultEvent;
use App\Models\LaboratoryResultStatus;
use App\Models\LaboratoryResultVersion;
use App\Services\LaboratoryResults\LaboratoryResultItemExtractionEligibility;
use App\Services\LaboratoryResults\LaboratoryResultPdfClassifier;
use App\Services\LaboratoryResults\LaboratoryResultRefreshPolicy;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ReclassifyLaboratoryResultVersionAction
{
    public function __construct(
        private LaboratoryResultPdfClassifier $classifier,
        private LaboratoryResultItemExtractionEligibility $extractionEligibility,
        private LaboratoryResultRefreshPolicy $refreshPolicy,
    ) {}

    public function execute(
        int $laboratoryResultVersionId,
        bool $dispatchExtraction = true,
    ): ReclassifyLaboratoryResultVersionResult {
        if (! $this->isAllowedEnvironment()) {
            throw new RuntimeException('Laboratory result reclassification is not allowed in this environment.');
        }

        $version = LaboratoryResultVersion::query()
            ->with([
                'resultStatus.laboratoryPurchaseItem',
                'resultStatus.laboratoryPurchase',
            ])
            ->find($laboratoryResultVersionId);

        if (! $version) {
            throw new DomainException('Laboratory result version not found.');
        }

        $status = $version->resultStatus;

        if ($status === null) {
            throw new DomainException('Laboratory result version has no associated status.');
        }

        $purchase = $status->laboratoryPurchase;
        $item = $status->laboratoryPurchaseItem;

        if ($purchase === null || $item === null) {
            throw new DomainException('Laboratory result version is missing purchase context.');
        }

        if (! Storage::exists($version->storage_path)) {
            throw new DomainException('Stored PDF not found for this version.');
        }

        $pdfBinary = Storage::get($version->storage_path);
        $sha256 = hash('sha256', $pdfBinary);

        if (! hash_equals($version->sha256, $sha256)) {
            throw new DomainException('Stored PDF hash does not match the version record.');
        }

        $classification = $this->classifier->classifyBinary($pdfBinary);
        $now = now();

        if ($this->classificationMatchesVersion($version, $classification)) {
            Log::info('laboratory_result_reclassification_idempotent_skip', [
                'result_version_id' => $version->id,
                'classification' => $classification->classification->value,
                'reason' => $classification->reason,
            ]);

            return new ReclassifyLaboratoryResultVersionResult(
                version: $version,
                changed: false,
                extractionDispatched: false,
                extractionSuppressed: false,
            );
        }

        $previousClassification = $version->classification;
        $previousReason = $version->classification_reason;
        $previousMatchedRule = $version->matched_rule;

        $extractionDispatched = false;
        $extractionSuppressed = false;

        DB::transaction(function () use (
            $version,
            $status,
            $item,
            $purchase,
            $classification,
            $now,
            $previousClassification,
            $previousReason,
            $previousMatchedRule,
            &$extractionDispatched,
            &$extractionSuppressed,
            $dispatchExtraction,
        ): void {
            $fromStatus = $status->status;
            $targetStatus = $this->statusForClassification($classification->classification);

            $version->fill([
                'classification' => $classification->classification,
                'classification_reason' => $classification->reason,
                'matched_rule' => $classification->matchedRule,
                'classifier' => LaboratoryResultPdfClassifier::CLASSIFIER_NAME,
                'classified_at' => $now,
            ]);
            $version->save();

            $statusUpdates = [
                'last_checked_at' => $now,
                'status' => $targetStatus,
            ];

            if ($targetStatus === LaboratoryResultStatusEnum::Complete && $status->interpreted_at === null) {
                $statusUpdates['interpreted_at'] = $now;
            }

            $status->fill($statusUpdates);
            $status->save();

            $this->recordEvent(
                $status,
                $version,
                LaboratoryResultEventType::Reclassified,
                fromStatus: $fromStatus,
                toStatus: $targetStatus,
                metadata: [
                    'from_classification' => $previousClassification?->value,
                    'to_classification' => $classification->classification->value,
                    'from_reason' => $previousReason,
                    'to_reason' => $classification->reason,
                    'from_matched_rule' => $previousMatchedRule,
                    'to_matched_rule' => $classification->matchedRule,
                    'classifier' => LaboratoryResultPdfClassifier::CLASSIFIER_NAME,
                    'sha256_short' => substr($version->sha256, 0, 12),
                    'storage_path' => $version->storage_path,
                ],
            );

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
                    'reclassification' => true,
                    'sha256_short' => substr($version->sha256, 0, 12),
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
                $this->refreshPolicy->markTerminal($status, $version, reason: 'reclassified_'.$targetStatus->value);
            }

            if ($dispatchExtraction && config('laboratory-results.structured_extraction.enabled', false)) {
                if ($targetStatus === LaboratoryResultStatusEnum::Complete) {
                    $eligibility = $this->extractionEligibility->evaluate($item, $purchase->brand);

                    if ($eligibility->eligible) {
                        $extractionDispatched = true;
                    } else {
                        $extractionSuppressed = true;

                        $this->recordEvent(
                            $status,
                            $version,
                            LaboratoryResultEventType::ExtractionSuppressed,
                            metadata: [
                                'purchase_item_id' => $item->id,
                                'purchase_item_name' => $item->name,
                                'gda_id' => $item->gda_id,
                                'reason' => $eligibility->reason,
                                'source' => $eligibility->source,
                                'classification' => $classification->classification->value,
                                'reclassification' => true,
                            ],
                        );
                    }
                }
            }
        });

        if ($extractionDispatched) {
            ExtractLaboratoryResultReportJob::dispatch($version->id);
        }

        Log::info('laboratory_result_reclassified', [
            'result_version_id' => $version->id,
            'purchase_id' => $purchase->id,
            'purchase_item_id' => $item->id,
            'from_classification' => $previousClassification?->value,
            'to_classification' => $classification->classification->value,
            'extraction_dispatched' => $extractionDispatched,
            'extraction_suppressed' => $extractionSuppressed,
        ]);

        return new ReclassifyLaboratoryResultVersionResult(
            version: $version->fresh(),
            changed: true,
            extractionDispatched: $extractionDispatched,
            extractionSuppressed: $extractionSuppressed,
        );
    }

    private function isAllowedEnvironment(): bool
    {
        $allowed = config('laboratory-results.reclassification.allowed_environments', ['local', 'testing']);

        return in_array(app()->environment(), $allowed, true);
    }

    private function classificationMatchesVersion(
        LaboratoryResultVersion $version,
        \App\Services\LaboratoryResults\LaboratoryResultPdfClassificationResult $classification,
    ): bool {
        return $version->classification === $classification->classification
            && $version->classification_reason === $classification->reason
            && $version->matched_rule === $classification->matchedRule
            && $version->classifier === LaboratoryResultPdfClassifier::CLASSIFIER_NAME;
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
            metadata: ['reclassification' => true],
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
