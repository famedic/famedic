<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryResultExtractionComparisonOutcome;

class LaboratoryResultExtractionQaConflictReporter
{
    /**
     * @param  list<LaboratoryResultExtractionComparisonItem>  $items
     * @return list<array<string, mixed>>
     */
    public function summarize(int $versionId, array $items, bool $verbose = false): array
    {
        $conflicts = [];

        foreach ($items as $item) {
            if ($item->outcome !== LaboratoryResultExtractionComparisonOutcome::Conflict) {
                continue;
            }

            $entry = [
                'version_id' => $versionId,
                'analyte_key' => $item->analyteKey,
                'analyte_code' => $item->analyteCode,
                'conflict_fields' => $item->conflictFields,
                'source_priority' => $item->sourcePriority->value,
                'requires_review' => $item->requiresReview,
                'unit_conflict' => in_array('unit', $item->conflictFields, true),
                'reference_conflict' => $this->hasReferenceConflict($item->conflictFields),
            ];

            if ($verbose) {
                $entry['text_value'] = $item->textCandidate?->numericValue ?? $item->textCandidate?->textValue;
                $entry['vision_value'] = $item->visionCandidate?->numericValue ?? $item->visionCandidate?->textValue;
                $entry['text_unit'] = $item->textCandidate?->unit;
                $entry['vision_unit'] = $item->visionCandidate?->unit;
                $entry['text_reference'] = $item->textCandidate?->referenceText;
                $entry['vision_reference'] = $item->visionCandidate?->referenceText;
            }

            $conflicts[] = $entry;
        }

        return $conflicts;
    }

    /**
     * @param  list<string>  $fields
     */
    private function hasReferenceConflict(array $fields): bool
    {
        foreach (['reference_low', 'reference_high', 'reference_text'] as $field) {
            if (in_array($field, $fields, true)) {
                return true;
            }
        }

        return false;
    }
}
