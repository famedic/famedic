<?php

namespace App\Services\LaboratoryPreparation\Compatibility;

use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionCategory;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionParseStudyResult;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionRequirement;

final class GeneralPreparationCompatibilityEvaluator
{
    /** @var list<string> */
    private const REAL_PREPARATION_CATEGORIES = [
        LaboratoryInstructionCategory::FASTING,
        LaboratoryInstructionCategory::AGE_CONDITION,
        LaboratoryInstructionCategory::HYDRATION,
        LaboratoryInstructionCategory::URINE_SIMPLE,
        LaboratoryInstructionCategory::URINE_24H,
        LaboratoryInstructionCategory::STOOL,
        LaboratoryInstructionCategory::SEMEN,
        LaboratoryInstructionCategory::GYNECOLOGICAL,
        LaboratoryInstructionCategory::MEDICATION,
        LaboratoryInstructionCategory::CREATININE_CONTRAST,
        LaboratoryInstructionCategory::METAL_IMPLANT,
        LaboratoryInstructionCategory::DIET_SUBSTANCE_EXERCISE,
        LaboratoryInstructionCategory::SAMPLE_TIMING,
        LaboratoryInstructionCategory::CONTAINER_PRESERVATIVE,
    ];

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

        $diet = $this->collectCategoryBlock($studies, LaboratoryInstructionCategory::DIET_SUBSTANCE_EXERCISE);
        if ($diet !== null) {
            $rules[] = 'R15';
            $blocks['diet_substance_exercise'] = $diet;
        }

        $timing = $this->collectSampleTimingBlock($studies);
        if ($timing !== null) {
            $rules[] = 'R16';
            $blocks['sample_timing'] = $timing;
        }

        $noPreparation = $this->evaluateNoPreparation($studies);
        if ($noPreparation['fallback']) {
            return [
                'fallback' => true,
                'reason' => $noPreparation['reason'],
                'category' => $noPreparation['category'],
                'rules' => array_values(array_unique([...$rules, 'R17'])),
                'blocks' => [],
            ];
        }
        if ($noPreparation['block'] !== null) {
            $rules[] = 'R17';
            $blocks['no_preparation'] = $noPreparation['block'];
        } elseif ($noPreparation['has_explicit_no_preparation']) {
            $rules[] = 'R17';
        }

        $containers = $this->collectCategoryBlock($studies, LaboratoryInstructionCategory::CONTAINER_PRESERVATIVE);
        if ($containers !== null) {
            $rules[] = 'R18';
            $blocks['container_preservative'] = $containers;
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
     * @return array{fallback: bool, reason: string|null, category: string|null, has_explicit_no_preparation: bool, block: array<string, mixed>|null}
     */
    private function evaluateNoPreparation(array $studies): array
    {
        $noPreparationEntries = $this->collectCategoryEntries($studies, LaboratoryInstructionCategory::NO_PREPARATION);
        if ($noPreparationEntries === []) {
            return [
                'fallback' => false,
                'reason' => null,
                'category' => null,
                'has_explicit_no_preparation' => false,
                'block' => null,
            ];
        }

        if ($this->hasRealPreparation($studies)) {
            return [
                'fallback' => false,
                'reason' => null,
                'category' => null,
                'has_explicit_no_preparation' => true,
                'block' => null,
            ];
        }

        return [
            'fallback' => false,
            'reason' => null,
            'category' => null,
            'has_explicit_no_preparation' => true,
            'block' => [
                'message' => 'No requiere preparación especial ni ayuno.',
                'sources' => $this->dedupeSources($noPreparationEntries),
            ],
        ];
    }

    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     */
    private function hasRealPreparation(array $studies): bool
    {
        foreach ($studies as $study) {
            foreach ($study->requirements as $requirement) {
                if ($requirement->requirementType === 'unrecognized_fragment') {
                    continue;
                }

                if (in_array($requirement->category, self::REAL_PREPARATION_CATEGORIES, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     */
    private function collectCategoryBlock(array $studies, string $category): ?array
    {
        $blocks = [];

        foreach ($studies as $study) {
            $entries = [];

            foreach ($study->requirements as $requirement) {
                if ($requirement->category !== $category || $requirement->requirementType === 'unrecognized_fragment') {
                    continue;
                }

                $entries[] = $this->sourceEntry($requirement);
            }

            if ($entries === []) {
                continue;
            }

            $blocks[] = [
                'study_ids' => [$study->studyId],
                'study_names' => [$study->studyName],
                'sources' => $this->dedupeSources($entries),
            ];
        }

        return $blocks === [] ? null : ['blocks' => $blocks];
    }

    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     */
    private function collectSampleTimingBlock(array $studies): ?array
    {
        $blocks = [];

        foreach ($studies as $study) {
            $entries = [];

            foreach ($study->requirements as $requirement) {
                if ($requirement->requirementType === 'unrecognized_fragment') {
                    continue;
                }

                $isSampleTiming = $requirement->category === LaboratoryInstructionCategory::SAMPLE_TIMING;
                $isOperationalTiming = $requirement->category === LaboratoryInstructionCategory::APPOINTMENT
                    && ($requirement->normalizedValue['kind'] ?? null) === 'weekday_branch_restriction';

                if (! $isSampleTiming && ! $isOperationalTiming) {
                    continue;
                }

                $entries[] = $this->sourceEntry($requirement);
            }

            if ($entries === []) {
                continue;
            }

            $blocks[] = [
                'study_ids' => [$study->studyId],
                'study_names' => [$study->studyName],
                'sources' => $this->dedupeSources($entries),
            ];
        }

        return $blocks === [] ? null : ['blocks' => $blocks];
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
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    private function dedupeSources(array $sources): array
    {
        $seen = [];
        $result = [];

        foreach ($sources as $source) {
            $key = ($source['category'] ?? '').'|'.($source['requirement_type'] ?? '').'|'.json_encode($source['normalized_value'] ?? []);
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
            'category' => $requirement->category,
            'requirement_type' => $requirement->requirementType,
            'normalized_value' => $requirement->normalizedValue,
        ];
    }
}
