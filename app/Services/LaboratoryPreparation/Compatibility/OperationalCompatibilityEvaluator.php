<?php

namespace App\Services\LaboratoryPreparation\Compatibility;

use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionCategory;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionParseStudyResult;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionRequirement;

final class OperationalCompatibilityEvaluator
{
    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     * @return array{
     *     fallback: bool,
     *     reason: string|null,
     *     category: string|null,
     *     rules: list<string>,
     *     blocks: array<string, mixed>
     * }
     */
    public function evaluate(array $studies): array
    {
        $rules = [];
        $blocks = [];

        $hasCreatinineOrContrast = $this->collectCategoryEntries($studies, LaboratoryInstructionCategory::CREATININE_CONTRAST) !== [];

        $medication = $this->evaluateMedication($studies);
        if ($medication['fallback']) {
            return $this->failure($medication, array_values(array_filter([
                'R09',
                $hasCreatinineOrContrast ? 'R10' : null,
            ])));
        }
        if ($medication['block'] !== null) {
            $rules[] = 'R09';
            $blocks['medication'] = $medication['block'];
        }

        $creatinine = $this->evaluateCreatinineContrast($studies);
        if ($creatinine['fallback']) {
            return $this->failure($creatinine, [...$rules, 'R10']);
        }
        if ($creatinine['block'] !== null) {
            $rules[] = 'R10';
            $blocks['creatinine_contrast'] = $creatinine['block'];
        }

        $documentation = $this->evaluateDocumentation($studies);
        if ($documentation['fallback']) {
            return $this->failure($documentation, $rules);
        }
        if ($documentation['block'] !== null) {
            $rules[] = 'R11';
            $blocks['documentation'] = $documentation['block'];
        }

        $appointment = $this->evaluateAppointment($studies);
        if ($appointment['fallback']) {
            return $this->failure($appointment, $rules);
        }
        if ($appointment['block'] !== null) {
            $rules[] = 'R12';
            $blocks['appointment'] = $appointment['block'];
        }

        $companion = $this->evaluateCompanionIdentification($studies);
        if ($companion['fallback']) {
            return $this->failure($companion, $rules);
        }
        if ($companion['block'] !== null) {
            $rules[] = 'R13';
            $blocks['companion_identification'] = $companion['block'];
        }

        $metal = $this->evaluateMetalImplants($studies);
        if ($metal['fallback']) {
            return $this->failure($metal, $rules);
        }
        if ($metal['block'] !== null) {
            $rules[] = 'R14';
            $blocks['metal_implant'] = $metal['block'];
        }

        return [
            'fallback' => false,
            'reason' => null,
            'category' => null,
            'rules' => array_values(array_unique($rules)),
            'blocks' => $blocks,
        ];
    }

    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     * @return array{fallback: bool, reason: string|null, category: string|null, block: array<string, mixed>|null}
     */
    private function evaluateMedication(array $studies): array
    {
        $entries = $this->collectCategoryEntries($studies, LaboratoryInstructionCategory::MEDICATION);

        if ($entries === []) {
            return ['fallback' => false, 'reason' => null, 'category' => null, 'block' => null];
        }

        $fingerprints = array_map(fn (array $entry) => $this->medicationFingerprint($entry), $entries);
        $unique = array_values(array_unique($fingerprints));

        if (count($unique) > 1) {
            return [
                'fallback' => true,
                'reason' => 'incompatible_medication_instructions',
                'category' => 'clinical_conflict',
                'block' => null,
            ];
        }

        return [
            'fallback' => false,
            'reason' => null,
            'category' => null,
            'block' => ['sources' => $this->dedupeSources($entries)],
        ];
    }

    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     * @return array{fallback: bool, reason: string|null, category: string|null, block: array<string, mixed>|null}
     */
    private function evaluateCreatinineContrast(array $studies): array
    {
        $entries = $this->collectCategoryEntries($studies, LaboratoryInstructionCategory::CREATININE_CONTRAST);

        if ($entries === []) {
            return ['fallback' => false, 'reason' => null, 'category' => null, 'block' => null];
        }

        $fingerprints = array_map(fn (array $entry) => $this->creatinineFingerprint($entry), $entries);
        $unique = array_values(array_unique($fingerprints));

        if (count($unique) > 1) {
            return [
                'fallback' => true,
                'reason' => 'incompatible_creatinine_requirements',
                'category' => 'clinical_conflict',
                'block' => null,
            ];
        }

        return [
            'fallback' => false,
            'reason' => null,
            'category' => null,
            'block' => ['sources' => $this->dedupeSources($entries)],
        ];
    }

    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     * @return array{fallback: bool, reason: string|null, category: string|null, block: array<string, mixed>|null}
     */
    private function evaluateDocumentation(array $studies): array
    {
        $entries = $this->collectCategoryEntries($studies, LaboratoryInstructionCategory::DOCUMENTATION);

        if ($entries === []) {
            return ['fallback' => false, 'reason' => null, 'category' => null, 'block' => null];
        }

        return [
            'fallback' => false,
            'reason' => null,
            'category' => null,
            'block' => ['sources' => $this->dedupeSources($entries)],
        ];
    }

    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     * @return array{fallback: bool, reason: string|null, category: string|null, block: array<string, mixed>|null}
     */
    private function evaluateAppointment(array $studies): array
    {
        $entries = $this->collectCategoryEntries($studies, LaboratoryInstructionCategory::APPOINTMENT);

        if ($entries === []) {
            return ['fallback' => false, 'reason' => null, 'category' => null, 'block' => null];
        }

        return [
            'fallback' => false,
            'reason' => null,
            'category' => null,
            'block' => ['sources' => $this->dedupeSources($entries)],
        ];
    }

    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     * @return array{fallback: bool, reason: string|null, category: string|null, block: array<string, mixed>|null}
     */
    private function evaluateCompanionIdentification(array $studies): array
    {
        $entries = $this->collectCategoryEntries($studies, LaboratoryInstructionCategory::COMPANION_IDENTIFICATION);

        if ($entries === []) {
            return ['fallback' => false, 'reason' => null, 'category' => null, 'block' => null];
        }

        $minAges = [];
        foreach ($entries as $entry) {
            $minAge = $entry['normalized_value']['minimum_age_years'] ?? null;
            if ($minAge !== null) {
                $minAges[] = (int) $minAge;
            }
        }

        if ($minAges !== [] && count(array_unique($minAges)) > 1) {
            return [
                'fallback' => true,
                'reason' => 'incompatible_companion_age_requirements',
                'category' => 'clinical_conflict',
                'block' => null,
            ];
        }

        return [
            'fallback' => false,
            'reason' => null,
            'category' => null,
            'block' => ['sources' => $this->dedupeSources($entries)],
        ];
    }

    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     * @return array{fallback: bool, reason: string|null, category: string|null, block: array<string, mixed>|null}
     */
    private function evaluateMetalImplants(array $studies): array
    {
        $entries = $this->collectCategoryEntries($studies, LaboratoryInstructionCategory::METAL_IMPLANT);

        if ($entries === []) {
            return ['fallback' => false, 'reason' => null, 'category' => null, 'block' => null];
        }

        return [
            'fallback' => false,
            'reason' => null,
            'category' => null,
            'block' => ['sources' => $this->dedupeSources($entries)],
        ];
    }

    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     * @return list<array<string, mixed>>
     */
    private function collectCategoryEntries(array $studies, string $category): array
    {
        $entries = [];

        foreach ($studies as $study) {
            foreach ($study->requirements as $requirement) {
                if ($requirement->category !== $category || $requirement->requirementType === 'unrecognized_fragment') {
                    continue;
                }

                $entries[] = $this->sourceEntry($requirement);
            }
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function medicationFingerprint(array $entry): string
    {
        $value = $entry['normalized_value'] ?? [];

        return json_encode([
            'kind' => $value['kind'] ?? null,
            'substance_text' => mb_strtolower(trim((string) ($value['substance_text'] ?? ''))),
            'duration' => $value['duration'] ?? null,
            'duration_unit' => $value['duration_unit'] ?? null,
            'hours_before' => $value['hours_before'] ?? null,
            'hours_after' => $value['hours_after'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function creatinineFingerprint(array $entry): string
    {
        $value = $entry['normalized_value'] ?? [];

        return json_encode([
            'kind' => $value['kind'] ?? null,
            'maximum_level' => $value['maximum_level'] ?? null,
            'range_min' => $value['range_min'] ?? null,
            'range_max' => $value['range_max'] ?? null,
            'validity_days' => $value['validity_days'] ?? null,
            'requires_normal_values' => $value['requires_normal_values'] ?? null,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    private function dedupeSources(array $sources): array
    {
        $seen = [];
        $result = [];

        foreach ($sources as $source) {
            $key = ($source['requirement_type'] ?? '').'|'.json_encode($source['normalized_value'] ?? []);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $source;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceEntry(LaboratoryInstructionRequirement $requirement): array
    {
        return [
            'study_id' => $requirement->studyId,
            'study_name' => $requirement->studyName,
            'source_span' => $requirement->sourceSpan,
            'requirement_type' => $requirement->requirementType,
            'normalized_value' => $requirement->normalizedValue,
        ];
    }

    /**
     * @param  array{fallback: bool, reason: string|null, category: string|null}  $result
     * @param  list<string>  $rules
     * @return array{fallback: bool, reason: string|null, category: string|null, rules: list<string>, blocks: array<string, mixed>}
     */
    private function failure(array $result, array $rules): array
    {
        return [
            'fallback' => true,
            'reason' => $result['reason'],
            'category' => $result['category'],
            'rules' => array_values(array_unique($rules)),
            'blocks' => [],
        ];
    }
}
