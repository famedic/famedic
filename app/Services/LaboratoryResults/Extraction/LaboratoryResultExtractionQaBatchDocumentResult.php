<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryResultExtractionComparisonOutcome;
use App\Enums\LaboratoryResultExtractionQaDocumentCategory;

final class LaboratoryResultExtractionQaBatchDocumentResult
{
    /**
     * @param  list<string>  $fallbackReasons
     * @param  list<array<string, mixed>>  $conflicts
     * @param  list<string>  $textOnlyAnalyteKeys
     * @param  list<string>  $visionOnlyAnalyteKeys
     */
    public function __construct(
        public readonly int $versionId,
        public readonly bool $success,
        public readonly ?string $source = null,
        public readonly ?string $textExtractionStatus = null,
        public readonly ?int $textObservationCount = null,
        public readonly ?int $visionObservationCount = null,
        public readonly int $matchCount = 0,
        public readonly int $conflictCount = 0,
        public readonly int $textOnlyCount = 0,
        public readonly int $visionOnlyCount = 0,
        public readonly int $unresolvedCount = 0,
        public readonly ?float $matchRate = null,
        public readonly ?float $visionCoverage = null,
        public readonly bool $visionExecuted = false,
        public readonly ?int $visionExecutionId = null,
        public readonly ?int $textPageCount = null,
        public readonly array $fallbackReasons = [],
        public readonly ?LaboratoryResultExtractionQaDocumentCategory $documentCategory = null,
        public readonly array $conflicts = [],
        public readonly array $textOnlyAnalyteKeys = [],
        public readonly array $visionOnlyAnalyteKeys = [],
        public readonly ?string $errorMessage = null,
    ) {}

    /**
     * @param  list<LaboratoryResultExtractionComparisonItem>  $comparisonItems
     * @param  list<array<string, mixed>>  $conflicts
     */
    public static function fromQaResult(
        LaboratoryResultExtractionQaResult $result,
        LaboratoryResultExtractionQaDocumentCategory $category,
        array $conflicts,
        ?int $textPageCount,
        array $comparisonItems = [],
    ): self {
        $report = $result->comparisonReport;
        $summary = $report->toSummaryArray();
        $textOnlyKeys = [];
        $visionOnlyKeys = [];

        foreach ($comparisonItems as $item) {
            if ($item->outcome === LaboratoryResultExtractionComparisonOutcome::TextOnly && $item->analyteKey !== null) {
                $textOnlyKeys[] = $item->analyteKey;
            }

            if ($item->outcome === LaboratoryResultExtractionComparisonOutcome::VisionOnly && $item->analyteKey !== null) {
                $visionOnlyKeys[] = $item->analyteKey;
            }
        }

        return new self(
            versionId: $result->version->id,
            success: true,
            source: $result->version->source,
            textExtractionStatus: $result->qaMetric->text_extraction_status,
            textObservationCount: (int) ($summary['text_observations'] ?? 0),
            visionObservationCount: (int) ($summary['vision_observations'] ?? 0),
            matchCount: $report->comparison->matchCount,
            conflictCount: $report->comparison->conflictCount,
            textOnlyCount: $report->comparison->textOnlyCount,
            visionOnlyCount: $report->comparison->visionOnlyCount,
            unresolvedCount: $report->comparison->unresolvedCount,
            matchRate: $report->matchRate(),
            visionCoverage: $report->visionCoverage(),
            visionExecuted: (bool) ($summary['vision_executed'] ?? false),
            visionExecutionId: $result->aiExecutionId,
            textPageCount: $textPageCount,
            fallbackReasons: $result->fallbackReasons,
            documentCategory: $category,
            conflicts: $conflicts,
            textOnlyAnalyteKeys: array_values(array_unique($textOnlyKeys)),
            visionOnlyAnalyteKeys: array_values(array_unique($visionOnlyKeys)),
        );
    }

    public static function failed(int $versionId, string $errorMessage, ?string $source = null): self
    {
        return new self(
            versionId: $versionId,
            success: false,
            source: $source,
            errorMessage: $errorMessage,
            documentCategory: LaboratoryResultExtractionQaDocumentCategory::NoComparable,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'version_id' => $this->versionId,
            'text_observation_count' => $this->textObservationCount,
            'vision_observation_count' => $this->visionObservationCount,
            'match' => $this->matchCount,
            'conflict' => $this->conflictCount,
            'text_only' => $this->textOnlyCount,
            'vision_only' => $this->visionOnlyCount,
            'unresolved' => $this->unresolvedCount,
            'match_rate' => $this->matchRate,
            'vision_coverage' => $this->visionCoverage,
            'fallback_reason' => implode(',', $this->fallbackReasons),
            'vision_executed' => $this->visionExecuted,
            'vision_execution_id' => $this->visionExecutionId,
            'document_category' => $this->documentCategory?->value,
            'source' => $this->source,
            'text_extraction_status' => $this->textExtractionStatus,
            'text_page_count' => $this->textPageCount,
        ];
    }
}
