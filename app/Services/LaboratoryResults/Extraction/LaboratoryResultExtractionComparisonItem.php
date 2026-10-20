<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryAnalyteResolutionStatus;
use App\Enums\LaboratoryObservationSourcePriority;
use App\Enums\LaboratoryReferenceComparisonOutcome;
use App\Enums\LaboratoryResultExtractionComparisonOutcome;

final class LaboratoryResultExtractionComparisonItem
{
    /**
     * @param  list<string>  $conflictFields
     */
    public function __construct(
        public readonly LaboratoryResultExtractionComparisonOutcome $outcome,
        public readonly ?string $analyteKey,
        public readonly ?LaboratoryResultObservationCandidate $textCandidate = null,
        public readonly ?LaboratoryResultObservationCandidate $visionCandidate = null,
        public readonly array $conflictFields = [],
        public readonly ?string $analyteCode = null,
        public readonly LaboratoryAnalyteResolutionStatus $textResolutionStatus = LaboratoryAnalyteResolutionStatus::Unresolved,
        public readonly LaboratoryAnalyteResolutionStatus $visionResolutionStatus = LaboratoryAnalyteResolutionStatus::Unresolved,
        public readonly LaboratoryObservationSourcePriority $sourcePriority = LaboratoryObservationSourcePriority::TextPrimary,
        public readonly bool $requiresReview = false,
        public readonly ?string $unitEquivalenceKeyText = null,
        public readonly ?string $unitEquivalenceKeyVision = null,
        public readonly LaboratoryReferenceComparisonOutcome $referenceComparison = LaboratoryReferenceComparisonOutcome::Unknown,
    ) {}
}
