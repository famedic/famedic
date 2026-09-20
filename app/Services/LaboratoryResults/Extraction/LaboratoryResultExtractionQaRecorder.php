<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryResultExtractionQaDocumentCategory;
use App\Models\LaboratoryResultExtractionQaMetric;

class LaboratoryResultExtractionQaRecorder
{
    /**
     * @param  list<string>  $fallbackReasons
     */
    public function record(
        int $laboratoryResultVersionId,
        LaboratoryResultExtractionComparisonReport $report,
        ?int $textReportId = null,
        ?int $visionReportId = null,
        ?int $aiExecutionId = null,
        ?string $textExtractionStatus = null,
        ?string $visionExtractionStatus = null,
        ?string $textExtractorVersion = null,
        ?string $visionExtractorVersion = null,
        ?int $promptVersion = null,
        array $fallbackReasons = [],
        ?LaboratoryResultExtractionQaDocumentCategory $documentCategory = null,
        ?string $versionSource = null,
        bool $visionSkippedIdempotent = false,
        ?string $visionInputHash = null,
    ): LaboratoryResultExtractionQaMetric {
        $summary = $report->toSummaryArray();
        $summary['document_category'] = $documentCategory?->value;
        $summary['version_source'] = $versionSource;

        if ($visionInputHash !== null) {
            $summary['vision_input_hash'] = $visionInputHash;
        }

        if ($visionSkippedIdempotent && $aiExecutionId !== null) {
            $existing = LaboratoryResultExtractionQaMetric::query()
                ->where('laboratory_result_version_id', $laboratoryResultVersionId)
                ->where('ai_execution_id', $aiExecutionId)
                ->orderByDesc('id')
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return LaboratoryResultExtractionQaMetric::query()->create([
            'laboratory_result_version_id' => $laboratoryResultVersionId,
            'text_report_id' => $textReportId,
            'vision_report_id' => $visionReportId,
            'comparison_outcome' => $documentCategory?->value ?? $report->resolveComparisonOutcome(),
            'text_observation_count' => $report->textObservationCount,
            'vision_observation_count' => $report->visionObservationCount,
            'match_count' => $report->comparison->matchCount,
            'conflict_count' => $report->comparison->conflictCount,
            'vision_only_count' => $report->comparison->visionOnlyCount,
            'text_only_count' => $report->comparison->textOnlyCount,
            'unresolved_count' => $report->comparison->unresolvedCount,
            'match_rate' => $report->matchRate(),
            'conflict_rate' => $report->conflictRate(),
            'vision_coverage' => $report->visionCoverage(),
            'vision_only_rate' => $report->visionOnlyRate(),
            'fallback_reasons' => $fallbackReasons !== [] ? $fallbackReasons : null,
            'text_extraction_status' => $textExtractionStatus,
            'vision_extraction_status' => $visionExtractionStatus,
            'vision_confidence_avg' => $report->visionConfidenceAvg,
            'vision_page_count' => $report->visionPageCount,
            'ai_execution_id' => $aiExecutionId,
            'text_extractor_version' => $textExtractorVersion,
            'vision_extractor_version' => $visionExtractorVersion,
            'prompt_version' => $promptVersion,
            'shadow_mode' => $report->shadowMode,
            'summary' => $summary,
        ]);
    }
}
