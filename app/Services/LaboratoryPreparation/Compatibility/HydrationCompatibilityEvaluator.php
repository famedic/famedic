<?php

namespace App\Services\LaboratoryPreparation\Compatibility;

use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionCategory;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionParseStudyResult;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionRequirement;

final class HydrationCompatibilityEvaluator
{
    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     * @return array{
     *     fallback: bool,
     *     reason: string|null,
     *     category: string|null,
     *     hydration: array<string, mixed>|null,
     *     trace: array<string, mixed>
     * }
     */
    public function evaluate(array $studies): array
    {
        $entries = [];
        $waterAmounts = [];
        $hasFullBladder = false;
        $hasUrineStudy = false;
        $timingHours = [];

        foreach ($studies as $study) {
            foreach ($study->requirements as $requirement) {
                if (in_array($requirement->category, [
                    LaboratoryInstructionCategory::URINE_SIMPLE,
                    LaboratoryInstructionCategory::URINE_24H,
                ], true)) {
                    $hasUrineStudy = true;
                }

                if ($requirement->category === LaboratoryInstructionCategory::HYDRATION) {
                    $entries[] = $this->hydrationEntry($requirement);

                    $kind = (string) ($requirement->normalizedValue['kind'] ?? '');
                    if ($kind === 'water_intake') {
                        $waterAmounts[] = (float) $requirement->normalizedValue['amount'];
                        if (($requirement->normalizedValue['full_bladder'] ?? false) === true) {
                            $hasFullBladder = true;
                        }
                        if (isset($requirement->normalizedValue['hours_before'])) {
                            $timingHours[] = (int) $requirement->normalizedValue['hours_before'];
                        }
                    }
                    if ($kind === 'full_bladder') {
                        $hasFullBladder = true;
                    }
                }

                if ($requirement->category === LaboratoryInstructionCategory::SAMPLE_TIMING) {
                    $kind = (string) ($requirement->normalizedValue['kind'] ?? '');
                    if ($kind === 'hours_before') {
                        $timingHours[] = (int) $requirement->normalizedValue['hours'];
                    }
                }
            }
        }

        if ($entries === []) {
            return [
                'fallback' => false,
                'reason' => null,
                'category' => null,
                'hydration' => null,
                'trace' => ['entries' => []],
            ];
        }

        if ($hasFullBladder && $hasUrineStudy) {
            return [
                'fallback' => true,
                'reason' => 'hydration_bladder_conflicts_with_urine_collection',
                'category' => 'functional_ambiguity',
                'hydration' => null,
                'trace' => [
                    'entries' => $entries,
                    'has_full_bladder' => true,
                    'has_urine_study' => true,
                ],
            ];
        }

        $uniqueAmounts = array_values(array_unique($waterAmounts, SORT_REGULAR));
        if (count($uniqueAmounts) > 1) {
            return [
                'fallback' => true,
                'reason' => 'conflicting_water_intake_amounts',
                'category' => 'clinical_conflict',
                'hydration' => null,
                'trace' => [
                    'entries' => $entries,
                    'water_amounts' => $uniqueAmounts,
                ],
            ];
        }

        $uniqueTiming = array_values(array_unique($timingHours, SORT_REGULAR));
        if (count($uniqueTiming) > 1) {
            return [
                'fallback' => true,
                'reason' => 'ambiguous_hydration_timing_sequence',
                'category' => 'functional_ambiguity',
                'hydration' => null,
                'trace' => [
                    'entries' => $entries,
                    'timing_hours' => $uniqueTiming,
                ],
            ];
        }

        return [
            'fallback' => false,
            'reason' => null,
            'category' => null,
            'hydration' => [
                'water_liters' => $uniqueAmounts[0] ?? null,
                'hours_before_study' => $uniqueTiming[0] ?? null,
                'full_bladder_required' => $hasFullBladder,
                'sources' => $entries,
            ],
            'trace' => ['entries' => $entries],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hydrationEntry(LaboratoryInstructionRequirement $requirement): array
    {
        return [
            'study_id' => $requirement->studyId,
            'study_name' => $requirement->studyName,
            'source_span' => $requirement->sourceSpan,
            'requirement_type' => $requirement->requirementType,
            'normalized_value' => $requirement->normalizedValue,
        ];
    }
}
