<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryResultObservationValueType;
use App\Models\LaboratoryAnalyte;

final class LaboratoryResultExtractionValidator
{
    /**
     * @return array{valid: bool, errors: list<string>}
     */
    public function validateCandidate(
        LaboratoryResultObservationCandidate $candidate,
        ?LaboratoryAnalyte $analyte,
    ): array {
        $errors = [];

        if ($candidate->valueType === LaboratoryResultObservationValueType::Numeric) {
            if ($candidate->numericValue === null) {
                $errors[] = 'missing_numeric_value';
            }

            if ($candidate->textValue !== null) {
                $errors[] = 'numeric_and_text_value';
            }
        } elseif ($candidate->valueType === LaboratoryResultObservationValueType::Qualitative) {
            if ($candidate->textValue === null || trim($candidate->textValue) === '') {
                $errors[] = 'missing_text_value';
            }

            if ($candidate->numericValue !== null) {
                $errors[] = 'qualitative_with_numeric_value';
            }
        } else {
            $errors[] = 'unsupported_value_type';
        }

        if ($candidate->referenceLow !== null && $candidate->referenceHigh !== null) {
            if ($candidate->referenceLow > $candidate->referenceHigh) {
                $errors[] = 'reference_low_greater_than_high';
            }
        }

        if ($analyte === null) {
            $errors[] = 'analyte_unresolved';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }
}
