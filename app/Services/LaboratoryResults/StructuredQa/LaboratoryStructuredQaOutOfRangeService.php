<?php

namespace App\Services\LaboratoryResults\StructuredQa;

use App\Enums\LaboratoryResultAbnormalSource;
use App\Enums\LaboratoryResultReferenceStatus;
use App\Models\LaboratoryResultObservation;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultReferenceEvaluation;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultReferenceEvaluator;

class LaboratoryStructuredQaOutOfRangeService
{
    public function __construct(
        private readonly LaboratoryStructuredQaPersistenceService $persistenceService,
        private readonly LaboratoryResultReferenceEvaluator $referenceEvaluator,
    ) {}

    public function assertEnabled(): void
    {
        $this->persistenceService->assertEnabled();
    }

    public function assertAllowedEnvironment(): void
    {
        $this->persistenceService->assertAllowedEnvironment();
    }

    public function evaluate(LaboratoryStructuredQaRunOptions $options): LaboratoryStructuredQaOutOfRangeResult
    {
        $this->assertEnabled();
        $this->assertAllowedEnvironment();

        $query = LaboratoryResultObservation::query()
            ->with(['report', 'analyte'])
            ->whereHas('report', function ($reportQuery) use ($options) {
                $reportQuery->shadowQa();

                if ($options->versionId !== null) {
                    $reportQuery->where('laboratory_result_version_id', $options->versionId);
                }
            });

        if ($options->limit !== null && $options->limit > 0) {
            $query->limit($options->limit);
        }

        $observations = $query->get();

        $preview = [];
        $evaluatedCount = 0;
        $skippedCount = 0;
        $updatedCount = 0;
        $duplicatePrevented = 0;
        $lowCount = 0;
        $normalCount = 0;
        $highCount = 0;
        $unknownCount = 0;
        $notApplicableCount = 0;

        foreach ($observations as $observation) {
            $evaluation = $this->referenceEvaluator->evaluateDeterministic(
                valueType: $observation->value_type,
                numericValue: $observation->numeric_value !== null ? (float) $observation->numeric_value : null,
                referenceText: $observation->reference_text,
                referenceLow: $observation->reference_low !== null ? (float) $observation->reference_low : null,
                referenceHigh: $observation->reference_high !== null ? (float) $observation->reference_high : null,
                unit: $observation->unit ?? $observation->unit_raw,
                catalogUnit: $observation->analyte?->default_unit,
            );

            $this->incrementStatusBucket(
                $evaluation->status,
                $lowCount,
                $normalCount,
                $highCount,
                $unknownCount,
                $notApplicableCount,
            );

            if ($evaluation->evaluated) {
                $evaluatedCount++;
            } else {
                $skippedCount++;
            }

            $inputHash = $this->evaluationInputHash($observation, $evaluation);
            $existingHash = $observation->metadata['reference_evaluation']['input_hash'] ?? null;

            $preview[] = [
                'observation_id' => $observation->id,
                'report_id' => $observation->laboratory_result_report_id,
                'version_id' => $observation->report?->laboratory_result_version_id,
                'analyte_code' => $observation->analyte_code,
                'numeric_value' => $observation->numeric_value,
                'reference_text' => $observation->reference_text,
                'reference_kind' => $evaluation->referenceKind,
                'status' => $evaluation->status->value,
                'evaluated' => $evaluation->evaluated,
                'reason' => $evaluation->reason,
                'method' => $evaluation->method(),
            ];

            if ($options->dryRun) {
                continue;
            }

            if ($existingHash === $inputHash) {
                $duplicatePrevented++;

                continue;
            }

            $metadata = $observation->metadata ?? [];
            $metadata['reference_evaluation'] = array_merge(
                $evaluation->toMetadataPayload(),
                [
                    'input_hash' => $inputHash,
                    'evaluated_at' => now()->toIso8601String(),
                    'phase' => '8C-17E',
                ],
            );

            $observation->update([
                'reference_status' => $evaluation->status,
                'abnormal_flag' => in_array($evaluation->status, [
                    LaboratoryResultReferenceStatus::Low,
                    LaboratoryResultReferenceStatus::High,
                    LaboratoryResultReferenceStatus::Abnormal,
                ], true),
                'abnormal_source' => $evaluation->evaluated
                    ? LaboratoryResultAbnormalSource::Computed
                    : LaboratoryResultAbnormalSource::None,
                'metadata' => $metadata,
            ]);

            $updatedCount++;
        }

        return new LaboratoryStructuredQaOutOfRangeResult(
            observationsInput: $observations->count(),
            evaluatedCount: $evaluatedCount,
            skippedCount: $skippedCount,
            updatedCount: $updatedCount,
            duplicatePrevented: $duplicatePrevented,
            lowCount: $lowCount,
            normalCount: $normalCount,
            highCount: $highCount,
            unknownCount: $unknownCount,
            notApplicableCount: $notApplicableCount,
            dryRun: $options->dryRun,
            preview: $preview,
        );
    }

    private function evaluationInputHash(
        LaboratoryResultObservation $observation,
        LaboratoryResultReferenceEvaluation $evaluation,
    ): string {
        return hash('sha256', implode('|', [
            LaboratoryResultReferenceEvaluation::METHOD,
            (string) $observation->numeric_value,
            (string) $observation->value_type->value,
            (string) $observation->reference_text,
            (string) $observation->reference_low,
            (string) $observation->reference_high,
            (string) ($observation->unit ?? $observation->unit_raw),
            (string) ($observation->analyte?->default_unit),
            $evaluation->status->value,
            $evaluation->referenceKind,
        ]));
    }

    private function incrementStatusBucket(
        LaboratoryResultReferenceStatus $status,
        int &$lowCount,
        int &$normalCount,
        int &$highCount,
        int &$unknownCount,
        int &$notApplicableCount,
    ): void {
        match ($status) {
            LaboratoryResultReferenceStatus::Low => $lowCount++,
            LaboratoryResultReferenceStatus::Normal => $normalCount++,
            LaboratoryResultReferenceStatus::High => $highCount++,
            LaboratoryResultReferenceStatus::NotApplicable => $notApplicableCount++,
            default => $unknownCount++,
        };
    }
}
