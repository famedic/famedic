<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Models\LaboratoryResultExtractionQaMetric;
use App\Models\LaboratoryResultVersion;

final class LaboratoryResultExtractionQaResult
{
    /**
     * @param  list<string>  $fallbackReasons
     * @param  list<LaboratoryResultExtractionComparisonItem>  $comparisonItems
     */
    public function __construct(
        public readonly LaboratoryResultVersion $version,
        public readonly LaboratoryResultExtractionComparisonReport $comparisonReport,
        public readonly LaboratoryResultExtractionQaMetric $qaMetric,
        public readonly ?int $textReportId = null,
        public readonly ?int $visionReportId = null,
        public readonly ?int $aiExecutionId = null,
        public readonly array $fallbackReasons = [],
        public readonly ?string $textPublishedStatusBefore = null,
        public readonly ?string $textPublishedStatusAfter = null,
        public readonly array $comparisonItems = [],
        public readonly ?int $textPageCount = null,
    ) {}

    public function visionStatusLabel(): string
    {
        if (! $this->comparisonReport->visionExecuted) {
            return 'NOT_EXECUTED';
        }

        return $this->comparisonReport->shadowMode ? 'SHADOW_ONLY' : 'EXECUTED';
    }

    /**
     * @return array<string, mixed>
     */
    public function toCommandOutput(): array
    {
        $summary = $this->comparisonReport->toSummaryArray();

        return [
            'result_version_id' => $this->version->id,
            'text_report_id' => $this->textReportId,
            'vision_report_id' => $this->visionReportId,
            'ai_execution_id' => $this->aiExecutionId,
            'qa_metric_id' => $this->qaMetric->id,
            'summary' => $summary,
            'fallback_reasons' => $this->fallbackReasons,
            'vision_status' => $this->visionStatusLabel(),
            'shadow_mode' => $this->comparisonReport->shadowMode,
        ];
    }
}
