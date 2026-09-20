<?php

namespace App\Services\LaboratoryResults\StructuredQa;

use App\Enums\LaboratoryResultStructuredStatus;
use App\Enums\LaboratoryStructuredResultPromotionStatus;
use App\Models\LaboratoryResultObservation;
use App\Models\LaboratoryResultReport;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LaboratoryStructuredQaPromotionService
{
    public function __construct(
        private readonly LaboratoryStructuredResultPromotionGate $promotionGate,
    ) {}

    public function assertEnabled(): void
    {
        if (! config('laboratory-results.structured_shadow_qa.enabled', false)) {
            throw new RuntimeException(
                'Structured Shadow QA is disabled. Set LAB_RESULTS_STRUCTURED_SHADOW_QA_ENABLED=true.'
            );
        }
    }

    public function assertAllowedEnvironment(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Structured Shadow QA promotion gate cannot run in production.');
        }

        $allowed = config('laboratory-results.qa.allowed_environments', ['local', 'testing']);

        if (! in_array(app()->environment(), $allowed, true)) {
            throw new RuntimeException(
                'Structured Shadow QA promotion gate is only allowed in: '.implode(', ', $allowed)
            );
        }
    }

    public function evaluate(LaboratoryStructuredQaRunOptions $options): LaboratoryStructuredQaPromotionResult
    {
        $this->assertEnabled();
        $this->assertAllowedEnvironment();

        $query = LaboratoryResultObservation::query()
            ->with(['report.resultVersion', 'analyte'])
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

        $validatedCount = 0;
        $needsReviewCount = 0;
        $rejectedCount = 0;
        $updatedCount = 0;
        $duplicatePrevented = 0;
        $reasonCodeCounts = [];
        $preview = [];

        foreach ($observations as $observation) {
            $gateResult = $this->promotionGate->evaluate($observation);

            match ($gateResult->status) {
                LaboratoryStructuredResultPromotionStatus::Validated => $validatedCount++,
                LaboratoryStructuredResultPromotionStatus::NeedsReview => $needsReviewCount++,
                LaboratoryStructuredResultPromotionStatus::Rejected => $rejectedCount++,
                default => null,
            };

            foreach ($gateResult->reasonCodes as $code) {
                $reasonCodeCounts[$code] = ($reasonCodeCounts[$code] ?? 0) + 1;
            }

            $inputHash = $this->promotionInputHash($observation, $gateResult);
            $preview[] = [
                'observation_id' => $observation->id,
                'analyte_code' => $observation->analyte_code,
                'promotion_status' => $gateResult->status->value,
                'reason_codes' => $gateResult->reasonCodes,
                'reasons' => $gateResult->reasonsHuman,
            ];

            if ($options->dryRun) {
                continue;
            }

            $metadata = $observation->metadata ?? [];
            $existingHash = $metadata['promotion_evaluation']['input_hash'] ?? null;

            if ($existingHash === $inputHash) {
                $duplicatePrevented++;

                continue;
            }

            $metadata['promotion_evaluation'] = array_merge(
                $gateResult->toMetadataPayload(),
                [
                    'input_hash' => $inputHash,
                    'evaluated_at' => now()->toIso8601String(),
                    'phase' => '8C-18',
                ],
            );

            $observation->update(['metadata' => $metadata]);
            $updatedCount++;
        }

        if (! $options->dryRun) {
            $this->syncReportStructuredStatuses($observations->pluck('laboratory_result_report_id')->unique()->all());
        }

        return new LaboratoryStructuredQaPromotionResult(
            candidatesInput: $observations->count(),
            validatedCount: $validatedCount,
            needsReviewCount: $needsReviewCount,
            rejectedCount: $rejectedCount,
            updatedCount: $updatedCount,
            duplicatePrevented: $duplicatePrevented,
            dryRun: $options->dryRun,
            reasonCodeCounts: $reasonCodeCounts,
            preview: $preview,
        );
    }

    /**
     * @param  list<int>  $reportIds
     */
    private function syncReportStructuredStatuses(array $reportIds): void
    {
        foreach ($reportIds as $reportId) {
            DB::transaction(function () use ($reportId): void {
                $report = LaboratoryResultReport::query()->lockForUpdate()->find($reportId);

                if ($report === null || ! $report->isShadowQa()) {
                    return;
                }

                $observations = $report->observations()->get();

                if ($observations->isEmpty()) {
                    return;
                }

                $statuses = $observations->map(function (LaboratoryResultObservation $observation) {
                    $promotion = $observation->metadata['promotion_evaluation']['promotion_status'] ?? 'shadow';

                    return LaboratoryStructuredResultPromotionStatus::tryFrom((string) $promotion)
                        ?? LaboratoryStructuredResultPromotionStatus::Shadow;
                });

                $structuredStatus = LaboratoryResultStructuredStatus::Draft;

                if ($statuses->every(fn (LaboratoryStructuredResultPromotionStatus $s) => $s === LaboratoryStructuredResultPromotionStatus::Validated)) {
                    $structuredStatus = LaboratoryResultStructuredStatus::Validated;
                } elseif ($statuses->contains(fn (LaboratoryStructuredResultPromotionStatus $s) => $s === LaboratoryStructuredResultPromotionStatus::Rejected)) {
                    $structuredStatus = LaboratoryResultStructuredStatus::Draft;
                }

                $payload = $report->raw_extraction_payload ?? [];
                $payload['promotion_gate'] = [
                    'gate_version' => LaboratoryStructuredResultPromotionGateResult::GATE_VERSION,
                    'evaluated_at' => now()->toIso8601String(),
                    'validated_count' => $statuses->filter(fn ($s) => $s === LaboratoryStructuredResultPromotionStatus::Validated)->count(),
                    'needs_review_count' => $statuses->filter(fn ($s) => $s === LaboratoryStructuredResultPromotionStatus::NeedsReview)->count(),
                    'rejected_count' => $statuses->filter(fn ($s) => $s === LaboratoryStructuredResultPromotionStatus::Rejected)->count(),
                ];

                $report->update([
                    'structured_status' => $structuredStatus,
                    'raw_extraction_payload' => $payload,
                ]);
            });
        }
    }

    private function promotionInputHash(
        LaboratoryResultObservation $observation,
        LaboratoryStructuredResultPromotionGateResult $gateResult,
    ): string {
        return hash('sha256', implode('|', [
            LaboratoryStructuredResultPromotionGateResult::GATE_VERSION,
            (string) $observation->id,
            $gateResult->status->value,
            implode(',', $gateResult->reasonCodes),
            (string) $observation->numeric_value,
            (string) $observation->reference_text,
            (string) ($observation->metadata['reference_evaluation']['input_hash'] ?? ''),
        ]));
    }
}
