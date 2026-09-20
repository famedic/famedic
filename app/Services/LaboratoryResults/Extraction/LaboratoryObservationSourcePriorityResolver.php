<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryObservationSourcePriority;
use App\Enums\LaboratoryResultExtractionComparisonOutcome;

final class LaboratoryObservationSourcePriorityResolver
{
    public function resolve(LaboratoryResultExtractionComparisonItem $item): LaboratoryObservationSourcePriority
    {
        return match ($item->outcome) {
            LaboratoryResultExtractionComparisonOutcome::Match => LaboratoryObservationSourcePriority::VisionConfirmation,
            LaboratoryResultExtractionComparisonOutcome::Conflict => LaboratoryObservationSourcePriority::ConflictRequiresReview,
            LaboratoryResultExtractionComparisonOutcome::TextOnly => LaboratoryObservationSourcePriority::TextPrimary,
            LaboratoryResultExtractionComparisonOutcome::VisionOnly => LaboratoryObservationSourcePriority::VisionOnly,
            LaboratoryResultExtractionComparisonOutcome::Unresolved => LaboratoryObservationSourcePriority::ConflictRequiresReview,
        };
    }

    public function requiresReview(LaboratoryResultExtractionComparisonItem $item): bool
    {
        return in_array($this->resolve($item), [
            LaboratoryObservationSourcePriority::ConflictRequiresReview,
            LaboratoryObservationSourcePriority::VisionOnly,
        ], true);
    }
}
