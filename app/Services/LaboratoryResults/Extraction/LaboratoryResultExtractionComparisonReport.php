<?php

namespace App\Services\LaboratoryResults\Extraction;

final class LaboratoryResultExtractionComparisonReport
{
    public function __construct(
        public readonly LaboratoryResultExtractionComparisonSummary $comparison,
        public readonly int $textObservationCount,
        public readonly int $visionObservationCount,
        public readonly bool $visionExecuted,
        public readonly bool $shadowMode,
        public readonly ?float $visionConfidenceAvg = null,
        public readonly ?int $visionPageCount = null,
    ) {}

    public function comparablePairCount(): int
    {
        return $this->comparison->matchCount
            + $this->comparison->conflictCount
            + $this->comparison->unresolvedCount;
    }

    public function totalComparisonItemCount(): int
    {
        return $this->comparison->matchCount
            + $this->comparison->conflictCount
            + $this->comparison->visionOnlyCount
            + $this->comparison->textOnlyCount
            + $this->comparison->unresolvedCount;
    }

    public function matchRate(): ?float
    {
        $comparables = $this->comparablePairCount();

        if ($comparables === 0) {
            return null;
        }

        return round($this->comparison->matchCount / $comparables, 4);
    }

    public function conflictRate(): ?float
    {
        $comparables = $this->comparablePairCount();

        if ($comparables === 0) {
            return null;
        }

        return round($this->comparison->conflictCount / $comparables, 4);
    }

    public function visionCoverage(): ?float
    {
        if ($this->textObservationCount === 0) {
            return null;
        }

        return round($this->visionObservationCount / $this->textObservationCount, 4);
    }

    public function visionOnlyRate(): ?float
    {
        $total = $this->totalComparisonItemCount();

        if ($total === 0) {
            return null;
        }

        return round($this->comparison->visionOnlyCount / $total, 4);
    }

    public function textOnlyRate(): ?float
    {
        $total = $this->totalComparisonItemCount();

        if ($total === 0) {
            return null;
        }

        return round($this->comparison->textOnlyCount / $total, 4);
    }

    public function resolveComparisonOutcome(): string
    {
        if (! $this->visionExecuted) {
            return 'vision_skipped';
        }

        if ($this->comparison->conflictCount > 0) {
            return 'conflict_present';
        }

        if ($this->comparison->matchCount > 0 && $this->comparison->visionOnlyCount === 0 && $this->comparison->textOnlyCount === 0) {
            return 'full_match';
        }

        if ($this->comparison->visionOnlyCount > 0 || $this->comparison->textOnlyCount > 0) {
            return 'partial_overlap';
        }

        if ($this->visionObservationCount === 0 && $this->textObservationCount === 0) {
            return 'no_observations';
        }

        return 'mixed';
    }

    /**
     * @return array<string, int|float|null>
     */
    public function toSummaryArray(): array
    {
        return [
            'text_observations' => $this->textObservationCount,
            'vision_observations' => $this->visionObservationCount,
            'match' => $this->comparison->matchCount,
            'conflict' => $this->comparison->conflictCount,
            'text_only' => $this->comparison->textOnlyCount,
            'vision_only' => $this->comparison->visionOnlyCount,
            'unresolved' => $this->comparison->unresolvedCount,
            'match_rate' => $this->matchRate(),
            'conflict_rate' => $this->conflictRate(),
            'vision_coverage' => $this->visionCoverage(),
            'vision_only_rate' => $this->visionOnlyRate(),
            'text_only_rate' => $this->textOnlyRate(),
            'comparable_pairs' => $this->comparablePairCount(),
            'comparison_outcome' => $this->resolveComparisonOutcome(),
            'shadow_mode' => $this->shadowMode,
            'vision_executed' => $this->visionExecuted,
        ];
    }
}
