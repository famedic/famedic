<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryResultExtractionQaDocumentCategory;

class LaboratoryResultExtractionQaDocumentClassifier
{
    public function classify(LaboratoryResultExtractionComparisonReport $report): LaboratoryResultExtractionQaDocumentCategory
    {
        $comparison = $report->comparison;
        $comparablePairs = $report->comparablePairCount();

        if ($comparablePairs === 0) {
            return LaboratoryResultExtractionQaDocumentCategory::NoComparable;
        }

        if ($comparison->conflictCount > 0) {
            return LaboratoryResultExtractionQaDocumentCategory::Conflict;
        }

        if ($comparison->matchCount === 0 && $comparison->visionOnlyCount > 0 && $comparison->textOnlyCount === 0) {
            return LaboratoryResultExtractionQaDocumentCategory::VisionOnly;
        }

        if ($comparison->matchCount === 0 && $comparison->textOnlyCount > 0 && $comparison->visionOnlyCount === 0) {
            return LaboratoryResultExtractionQaDocumentCategory::TextOnly;
        }

        if ($comparison->matchCount > 0 && $comparison->textOnlyCount === 0 && $comparison->visionOnlyCount === 0) {
            return LaboratoryResultExtractionQaDocumentCategory::HighOverlap;
        }

        if ($comparison->matchCount > 0) {
            return LaboratoryResultExtractionQaDocumentCategory::PartialOverlap;
        }

        return LaboratoryResultExtractionQaDocumentCategory::NoComparable;
    }
}
