<?php

namespace App\Services\LaboratoryPreparation\Compatibility;

use App\Services\LaboratoryPreparation\LaboratoryPreparationPatientContext;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionCategory;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionParseStudyResult;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionRequirement;

final class AgeConditionResolver
{
    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     * @return array{
     *     intervals: list<FastingHourInterval>,
     *     fallback: bool,
     *     reason: string|null,
     *     trace: array<string, mixed>
     * }
     */
    public function resolveFastingIntervals(
        array $studies,
        LaboratoryPreparationPatientContext $patient,
    ): array {
        $intervals = [];
        $trace = [
            'age_source' => $patient->ageSource,
            'age_years' => $patient->ageYears,
            'patient_label' => $patient->label,
            'selected_requirements' => [],
            'excluded_requirements' => [],
        ];

        foreach ($studies as $study) {
            $fastingRequirements = collect($study->requirements)
                ->filter(fn (LaboratoryInstructionRequirement $requirement) => $requirement->category === LaboratoryInstructionCategory::FASTING)
                ->values()
                ->all();

            $ageRequirements = collect($study->requirements)
                ->filter(fn (LaboratoryInstructionRequirement $requirement) => $requirement->category === LaboratoryInstructionCategory::AGE_CONDITION)
                ->values()
                ->all();

            $pediatricThresholds = collect($ageRequirements)
                ->map(fn (LaboratoryInstructionRequirement $requirement) => (int) ($requirement->normalizedValue['max_years'] ?? 0))
                ->filter(fn (int $years) => $years > 0)
                ->values()
                ->all();

            $studyHasPediatricFasting = collect($fastingRequirements)
                ->contains(fn (LaboratoryInstructionRequirement $fastingRequirement) => $this->linkedPediatricMaxAge(
                    $fastingRequirement,
                    $ageRequirements,
                    $study->sourceText,
                ) !== null);

            foreach ($fastingRequirements as $fastingRequirement) {
                $linkedMaxAge = $this->linkedPediatricMaxAge($fastingRequirement, $ageRequirements, $study->sourceText);
                $applies = $this->fastingAppliesToPatient(
                    fastingRequirement: $fastingRequirement,
                    linkedMaxAgeYears: $linkedMaxAge,
                    patient: $patient,
                    pediatricThresholds: $pediatricThresholds,
                    studyHasPediatricFasting: $studyHasPediatricFasting,
                );

                if ($applies === null) {
                    return [
                        'intervals' => [],
                        'fallback' => true,
                        'reason' => 'unresolved_age_condition',
                        'trace' => $trace,
                    ];
                }

                if (! $applies) {
                    $trace['excluded_requirements'][] = $this->requirementTrace($fastingRequirement, $linkedMaxAge);

                    continue;
                }

                $interval = FastingHourInterval::fromRequirement($fastingRequirement, $linkedMaxAge);
                if ($interval === null) {
                    $fastingKind = (string) ($fastingRequirement->normalizedValue['kind'] ?? '');
                    if (
                        $fastingKind === 'required'
                        && ($fastingRequirement->normalizedValue['hours_unspecified'] ?? false)
                    ) {
                        continue;
                    }

                    return [
                        'intervals' => [],
                        'fallback' => true,
                        'reason' => 'fasting_hours_not_structured',
                        'trace' => $trace,
                    ];
                }

                $intervals[] = $interval;
                $trace['selected_requirements'][] = $this->requirementTrace($fastingRequirement, $linkedMaxAge);
            }
        }

        return [
            'intervals' => $intervals,
            'fallback' => false,
            'reason' => null,
            'trace' => $trace,
        ];
    }

    /**
     * @param  list<LaboratoryInstructionRequirement>  $ageRequirements
     */
    private function linkedPediatricMaxAge(
        LaboratoryInstructionRequirement $fastingRequirement,
        array $ageRequirements,
        string $sourceText,
    ): ?int {
        foreach ($ageRequirements as $ageRequirement) {
            $kind = (string) ($ageRequirement->normalizedValue['kind'] ?? '');
            if ($kind !== 'age_max') {
                continue;
            }

            $maxYears = (int) ($ageRequirement->normalizedValue['max_years'] ?? 0);
            if ($maxYears <= 0) {
                continue;
            }

            if ($this->requirementsAreLinked($ageRequirement, $fastingRequirement, $sourceText)) {
                return $maxYears;
            }
        }

        return null;
    }

    /**
     * @param  list<LaboratoryInstructionRequirement>  $ageRequirements
     */
    private function requirementsAreLinked(
        LaboratoryInstructionRequirement $ageRequirement,
        LaboratoryInstructionRequirement $fastingRequirement,
        string $sourceText,
    ): bool {
        if ($ageRequirement->sourceSpanStart <= $fastingRequirement->sourceSpanStart) {
            $between = mb_substr(
                $sourceText,
                $ageRequirement->sourceSpanEnd,
                $fastingRequirement->sourceSpanStart - $ageRequirement->sourceSpanEnd,
            );

            return mb_strlen(trim($between)) <= 80;
        }

        return false;
    }

    /**
     * @param  list<int>  $pediatricThresholds
     */
    private function fastingAppliesToPatient(
        LaboratoryInstructionRequirement $fastingRequirement,
        ?int $linkedMaxAgeYears,
        LaboratoryPreparationPatientContext $patient,
        array $pediatricThresholds,
        bool $studyHasPediatricFasting,
    ): ?bool {
        if ($linkedMaxAgeYears !== null) {
            if ($patient->ageYears === null) {
                return null;
            }

            return $patient->ageYears < $linkedMaxAgeYears;
        }

        if (
            $studyHasPediatricFasting
            && $patient->ageYears !== null
            && $pediatricThresholds !== []
            && $patient->ageYears < min($pediatricThresholds)
        ) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function requirementTrace(LaboratoryInstructionRequirement $requirement, ?int $linkedMaxAgeYears): array
    {
        return [
            'study_id' => $requirement->studyId,
            'study_name' => $requirement->studyName,
            'source_span' => $requirement->sourceSpan,
            'requirement_type' => $requirement->requirementType,
            'linked_max_age_years' => $linkedMaxAgeYears,
        ];
    }
}
