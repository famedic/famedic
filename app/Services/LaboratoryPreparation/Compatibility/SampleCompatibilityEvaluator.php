<?php

namespace App\Services\LaboratoryPreparation\Compatibility;

use App\Services\LaboratoryPreparation\LaboratoryPreparationPatientContext;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionCategory;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionParseStudyResult;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionRequirement;

final class SampleCompatibilityEvaluator
{
    /** @var list<string> */
    private const URINE_SIMPLE_SUPPORT_CATEGORIES = [
        LaboratoryInstructionCategory::URINE_SIMPLE,
        LaboratoryInstructionCategory::CONTAINER_PRESERVATIVE,
        LaboratoryInstructionCategory::GYNECOLOGICAL,
        LaboratoryInstructionCategory::SAMPLE_TIMING,
    ];

    /** @var list<string> */
    private const STOOL_SUPPORT_CATEGORIES = [
        LaboratoryInstructionCategory::STOOL,
        LaboratoryInstructionCategory::CONTAINER_PRESERVATIVE,
    ];

    /** @var list<string> */
    private const SEMEN_SUPPORT_CATEGORIES = [
        LaboratoryInstructionCategory::SEMEN,
        LaboratoryInstructionCategory::GYNECOLOGICAL,
        LaboratoryInstructionCategory::MEDICATION,
        LaboratoryInstructionCategory::DIET_SUBSTANCE_EXERCISE,
        LaboratoryInstructionCategory::SAMPLE_TIMING,
        LaboratoryInstructionCategory::CONTAINER_PRESERVATIVE,
    ];

    /** @var list<string> */
    private const GYNECOLOGICAL_SUPPORT_CATEGORIES = [
        LaboratoryInstructionCategory::GYNECOLOGICAL,
        LaboratoryInstructionCategory::APPOINTMENT,
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
    public function evaluate(array $studies, LaboratoryPreparationPatientContext $patient): array
    {
        $rules = [];
        $blocks = [];

        $urineSimple = $this->evaluateUrineSimple($studies);
        if ($urineSimple['fallback']) {
            return $this->failure($urineSimple, [...$rules, 'R04']);
        }
        if ($urineSimple['block'] !== null) {
            $rules[] = 'R04';
            $blocks['urine_simple'] = $urineSimple['block'];
        }

        $urine24 = $this->evaluateUrine24h($studies);
        if ($urine24['fallback']) {
            return $this->failure($urine24, [...$rules, 'R05']);
        }
        if ($urine24['block'] !== null) {
            $rules[] = 'R05';
            $blocks['urine_24h'] = $urine24['block'];
        }

        $stool = $this->evaluateStool($studies);
        if ($stool['fallback']) {
            return $this->failure($stool, [...$rules, 'R06']);
        }
        if ($stool['block'] !== null) {
            $rules[] = 'R06';
            $blocks['stool'] = $stool['block'];
        }

        $semen = $this->evaluateSemen($studies);
        if ($semen['fallback']) {
            return $this->failure($semen, [...$rules, 'R07']);
        }
        if ($semen['block'] !== null) {
            $rules[] = 'R07';
            $blocks['semen'] = $semen['block'];
        }

        $gynecological = $this->evaluateGynecological($studies, $patient, $blocks);
        if ($gynecological['fallback']) {
            if ($this->orderHasUrineSimpleStudy($studies)) {
                $rules[] = 'R04';
            }

            return $this->failure($gynecological, [...$rules, 'R08']);
        }
        if ($gynecological['block'] !== null) {
            $rules[] = 'R08';
            $blocks['gynecological'] = $gynecological['block'];
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
    private function evaluateUrineSimple(array $studies): array
    {
        $studyBlocks = [];

        foreach ($studies as $study) {
            if (! $this->studyIsUrineSimple($study)) {
                continue;
            }

            if ($this->studyIsUrine24h($study)) {
                continue;
            }

            $requirements = $this->collectUrineSimpleRequirements($study);
            if ($requirements === []) {
                continue;
            }

            $studyBlocks[] = [
                'study_ids' => [$study->studyId],
                'study_names' => [$study->studyName],
                'sources' => array_map(fn (LaboratoryInstructionRequirement $r) => $this->sourceEntry($r), $requirements),
            ];
        }

        if ($studyBlocks === []) {
            return ['fallback' => false, 'reason' => null, 'category' => null, 'block' => null];
        }

        $merged = $this->mergeIdenticalBlocks($studyBlocks);
        if ($merged === null) {
            return [
                'fallback' => true,
                'reason' => 'incompatible_urine_simple_protocols',
                'category' => 'clinical_conflict',
                'block' => null,
            ];
        }

        return [
            'fallback' => false,
            'reason' => null,
            'category' => null,
            'block' => ['blocks' => $merged],
        ];
    }

    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     * @return array{fallback: bool, reason: string|null, category: string|null, block: array<string, mixed>|null}
     */
    private function evaluateUrine24h(array $studies): array
    {
        $blocks = [];

        foreach ($studies as $study) {
            if (! $this->studyIsUrine24h($study)) {
                continue;
            }

            $requirements = $this->collectUrine24hRequirements($study);

            $blocks[] = [
                'study_ids' => [$study->studyId],
                'study_names' => [$study->studyName],
                'sources' => array_map(fn (LaboratoryInstructionRequirement $r) => $this->sourceEntry($r), $requirements),
            ];
        }

        if ($blocks === []) {
            return ['fallback' => false, 'reason' => null, 'category' => null, 'block' => null];
        }

        return [
            'fallback' => false,
            'reason' => null,
            'category' => null,
            'block' => ['blocks' => $blocks],
        ];
    }

    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     * @return array{fallback: bool, reason: string|null, category: string|null, block: array<string, mixed>|null}
     */
    private function evaluateStool(array $studies): array
    {
        foreach ($studies as $study) {
            if (! $this->studyIsStool($study)) {
                continue;
            }

            if ($this->studyNameImpliesMultipleSamplesWithoutSchedule($study)) {
                return [
                    'fallback' => true,
                    'reason' => 'ambiguous_stool_sample_schedule',
                    'category' => 'functional_ambiguity',
                    'block' => null,
                ];
            }
        }

        $studyBlocks = [];

        foreach ($studies as $study) {
            if (! $this->studyIsStool($study)) {
                continue;
            }

            $requirements = $this->collectStoolRequirements($study);
            $studyBlocks[] = [
                'study_ids' => [$study->studyId],
                'study_names' => [$study->studyName],
                'sources' => array_map(fn (LaboratoryInstructionRequirement $r) => $this->sourceEntry($r), $requirements),
            ];
        }

        if ($studyBlocks === []) {
            return ['fallback' => false, 'reason' => null, 'category' => null, 'block' => null];
        }

        $merged = $this->mergeIdenticalBlocks($studyBlocks);

        return [
            'fallback' => false,
            'reason' => null,
            'category' => null,
            'block' => ['blocks' => $merged ?? $studyBlocks],
        ];
    }

    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     * @return array{fallback: bool, reason: string|null, category: string|null, block: array<string, mixed>|null}
     */
    private function evaluateSemen(array $studies): array
    {
        $studyBlocks = [];

        foreach ($studies as $study) {
            if (! $this->studyIsSemen($study)) {
                continue;
            }

            $requirements = $this->collectSemenRequirements($study);
            $studyBlocks[] = [
                'study_ids' => [$study->studyId],
                'study_names' => [$study->studyName],
                'sources' => array_map(fn (LaboratoryInstructionRequirement $r) => $this->sourceEntry($r), $requirements),
                'abstinence_days' => $this->extractSemenAbstinenceDays($requirements),
            ];
        }

        if ($studyBlocks === []) {
            return ['fallback' => false, 'reason' => null, 'category' => null, 'block' => null];
        }

        if (count($studyBlocks) > 1 && ! $this->semenAbstinenceRangesCompatible($studyBlocks)) {
            return [
                'fallback' => true,
                'reason' => 'incompatible_semen_abstinence_intervals',
                'category' => 'clinical_conflict',
                'block' => null,
            ];
        }

        $blocks = array_map(function (array $block): array {
            unset($block['abstinence_days']);

            return $block;
        }, $studyBlocks);

        $merged = $this->mergeIdenticalBlocks($blocks);

        return [
            'fallback' => false,
            'reason' => null,
            'category' => null,
            'block' => ['blocks' => $merged ?? $blocks],
        ];
    }

    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     * @param  array<string, mixed>  $existingBlocks
     * @return array{fallback: bool, reason: string|null, category: string|null, block: array<string, mixed>|null}
     */
    private function evaluateGynecological(
        array $studies,
        LaboratoryPreparationPatientContext $patient,
        array $existingBlocks,
    ): array {
        $hasStandaloneUrine = isset($existingBlocks['urine_simple']);

        foreach ($studies as $study) {
            if (
                $this->studyHasMultiSampleGynecologicalAmbiguity($study)
                && $hasStandaloneUrine
                && $this->orderHasDistinctUrineSimpleStudy($studies)
            ) {
                return [
                    'fallback' => true,
                    'reason' => 'ambiguous_gynecological_sample_with_urine_order',
                    'category' => 'functional_ambiguity',
                    'block' => null,
                ];
            }
        }

        $gynecologyStudies = array_values(array_filter(
            $studies,
            fn (LaboratoryInstructionParseStudyResult $study) => $this->isGynecologicalPreparationStudy($study),
        ));

        if ($gynecologyStudies === []) {
            return ['fallback' => false, 'reason' => null, 'category' => null, 'block' => null];
        }

        $perStudyBlocks = [];

        foreach ($gynecologyStudies as $study) {
            $requirements = $this->collectRequirements($study, self::GYNECOLOGICAL_SUPPORT_CATEGORIES);
            $perStudyBlocks[] = [
                'study_ids' => [$study->studyId],
                'study_names' => [$study->studyName],
                'sources' => array_map(fn (LaboratoryInstructionRequirement $r) => $this->sourceEntry($r), $requirements),
                'multi_sample_variants' => $this->studyHasMultiSampleGynecologicalAmbiguity($study),
            ];
        }

        if (count($perStudyBlocks) === 1) {
            return [
                'fallback' => false,
                'reason' => null,
                'category' => null,
                'block' => ['blocks' => $perStudyBlocks],
            ];
        }

        $merged = $this->mergeGynecologicalBlocks($perStudyBlocks);
        if ($merged === null) {
            return [
                'fallback' => true,
                'reason' => 'incompatible_gynecological_preparation',
                'category' => 'clinical_conflict',
                'block' => null,
            ];
        }

        return [
            'fallback' => false,
            'reason' => null,
            'category' => null,
            'block' => ['blocks' => [$merged]],
        ];
    }

    private function isGynecologicalPreparationStudy(LaboratoryInstructionParseStudyResult $study): bool
    {
        if ($this->studyIsSemen($study) && ! preg_match('/\b(?:papanicolaou|citolog[ií]a\s+vaginal)\b/iu', $study->studyName)) {
            return false;
        }

        if ($this->studyHasMultiSampleGynecologicalAmbiguity($study)) {
            return true;
        }

        if ($this->studyHasCategory($study, LaboratoryInstructionCategory::GYNECOLOGICAL)) {
            foreach ($study->requirements as $requirement) {
                if ($requirement->category !== LaboratoryInstructionCategory::GYNECOLOGICAL) {
                    continue;
                }

                if ($requirement->requirementType === 'no_menstruation' && $this->studyIsUrineSimple($study)) {
                    continue;
                }

                return true;
            }
        }

        return (bool) preg_match('/\b(?:papanicolaou|citolog[ií]a\s+vaginal)\b/iu', $study->studyName);
    }

    private function studyHasMultiSampleGynecologicalAmbiguity(LaboratoryInstructionParseStudyResult $study): bool
    {
        return (bool) preg_match(
            '/(?:^|\n)\s*Muestra\s+(?:Anal|Ocular|Cervico|de\s+orina)\b/iu',
            $study->sourceText,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>|null
     */
    private function mergeIdenticalBlocks(array $blocks): ?array
    {
        if ($blocks === []) {
            return [];
        }

        $fingerprints = array_map(fn (array $block) => $this->blockFingerprint($block), $blocks);
        $unique = array_values(array_unique($fingerprints));

        if (count($unique) > 1) {
            return null;
        }

        $merged = $blocks[0];
        $merged['study_ids'] = array_values(array_unique(array_merge(...array_map(
            fn (array $block) => $block['study_ids'],
            $blocks,
        ))));
        $merged['study_names'] = array_values(array_unique(array_merge(...array_map(
            fn (array $block) => $block['study_names'],
            $blocks,
        ))));

        return [$merged];
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return array<string, mixed>|null
     */
    private function mergeGynecologicalBlocks(array $blocks): ?array
    {
        if ($blocks === []) {
            return null;
        }

        $sources = [];
        $studyIds = [];
        $studyNames = [];
        $multiSample = false;

        foreach ($blocks as $block) {
            $studyIds = [...$studyIds, ...$block['study_ids']];
            $studyNames = [...$studyNames, ...$block['study_names']];
            $sources = [...$sources, ...$block['sources']];
            $multiSample = $multiSample || ($block['multi_sample_variants'] ?? false);
        }

        $dedupedSources = $this->dedupeSources($sources);

        return [
            'study_ids' => array_values(array_unique($studyIds)),
            'study_names' => array_values(array_unique($studyNames)),
            'sources' => $dedupedSources,
            'multi_sample_variants' => $multiSample,
        ];
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
     * @param  array<string, mixed>  $block
     */
    private function blockFingerprint(array $block): string
    {
        $sources = $block['sources'] ?? [];
        usort($sources, fn ($a, $b) => ($a['requirement_type'] ?? '') <=> ($b['requirement_type'] ?? ''));

        return json_encode(array_map(
            fn (array $source) => [
                'requirement_type' => $source['requirement_type'] ?? null,
                'normalized_value' => $source['normalized_value'] ?? null,
            ],
            $sources,
        ));
    }

    /**
     * @param  list<LaboratoryInstructionRequirement>  $requirements
     * @return array{min: int|null, max: int|null}|null
     */
    private function extractSemenAbstinenceDays(array $requirements): ?array
    {
        foreach ($requirements as $requirement) {
            if ($requirement->requirementType !== 'sexual_abstinence_days') {
                continue;
            }

            return [
                'min' => (int) ($requirement->normalizedValue['min_days'] ?? 0),
                'max' => (int) ($requirement->normalizedValue['max_days'] ?? 0),
            ];
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $studyBlocks
     */
    private function semenAbstinenceRangesCompatible(array $studyBlocks): bool
    {
        $ranges = array_values(array_filter(array_map(
            fn (array $block) => $block['abstinence_days'] ?? null,
            $studyBlocks,
        )));

        if (count($ranges) <= 1) {
            return true;
        }

        $min = max(array_map(fn (array $range) => $range['min'] ?? 0, $ranges));
        $max = min(array_map(fn (array $range) => $range['max'] ?? PHP_INT_MAX, $ranges));

        return $min <= $max;
    }

    private function studyNameImpliesMultipleSamplesWithoutSchedule(LaboratoryInstructionParseStudyResult $study): bool
    {
        if (! preg_match('/\b(tres|3)\s+muestras?\b/iu', $study->studyName)) {
            return false;
        }

        foreach ($study->requirements as $requirement) {
            if ($requirement->category !== LaboratoryInstructionCategory::SAMPLE_TIMING) {
                continue;
            }

            $kind = (string) ($requirement->normalizedValue['kind'] ?? '');
            if (in_array($kind, ['before_days', 'hours_before'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<LaboratoryInstructionRequirement>
     */
    private function collectUrineSimpleRequirements(LaboratoryInstructionParseStudyResult $study): array
    {
        $requirements = $this->collectRequirements($study, self::URINE_SIMPLE_SUPPORT_CATEGORIES);

        return $requirements !== []
            ? $requirements
            : $this->fallbackStudyRequirements($study, $this->studyIsUrineSimple($study));
    }

    /**
     * @return list<LaboratoryInstructionRequirement>
     */
    private function collectUrine24hRequirements(LaboratoryInstructionParseStudyResult $study): array
    {
        $requirements = $this->collectRequirements($study, [
            LaboratoryInstructionCategory::URINE_24H,
            LaboratoryInstructionCategory::CONTAINER_PRESERVATIVE,
            LaboratoryInstructionCategory::SAMPLE_TIMING,
        ]);

        return $requirements !== []
            ? $requirements
            : $this->fallbackStudyRequirements($study, $this->studyIsUrine24h($study));
    }

    /**
     * @return list<LaboratoryInstructionRequirement>
     */
    private function collectStoolRequirements(LaboratoryInstructionParseStudyResult $study): array
    {
        $requirements = $this->collectRequirements($study, self::STOOL_SUPPORT_CATEGORIES);

        return $requirements !== []
            ? $requirements
            : $this->fallbackStudyRequirements($study, $this->studyIsStool($study));
    }

    /**
     * @return list<LaboratoryInstructionRequirement>
     */
    private function collectSemenRequirements(LaboratoryInstructionParseStudyResult $study): array
    {
        $requirements = $this->collectRequirements($study, self::SEMEN_SUPPORT_CATEGORIES);

        return $requirements !== []
            ? $requirements
            : $this->fallbackStudyRequirements($study, $this->studyIsSemen($study));
    }

    /**
     * @return list<LaboratoryInstructionRequirement>
     */
    private function fallbackStudyRequirements(LaboratoryInstructionParseStudyResult $study, bool $applies): array
    {
        if (! $applies) {
            return [];
        }

        return array_values(array_filter(
            $study->requirements,
            fn (LaboratoryInstructionRequirement $requirement) => $requirement->category !== LaboratoryInstructionCategory::UNKNOWN
                && $requirement->requirementType !== 'unrecognized_fragment',
        ));
    }

    /**
     * @param  list<string>  $categories
     * @return list<LaboratoryInstructionRequirement>
     */
    private function collectRequirements(LaboratoryInstructionParseStudyResult $study, array $categories): array
    {
        return array_values(array_filter(
            $study->requirements,
            fn (LaboratoryInstructionRequirement $requirement) => in_array($requirement->category, $categories, true)
                && $requirement->requirementType !== 'unrecognized_fragment',
        ));
    }

    private function studyHasCategory(LaboratoryInstructionParseStudyResult $study, string $category): bool
    {
        return collect($study->requirements)->contains(
            fn (LaboratoryInstructionRequirement $requirement) => $requirement->category === $category,
        );
    }

    private function studyIsUrineSimple(LaboratoryInstructionParseStudyResult $study): bool
    {
        if ($this->studyHasMultiSampleGynecologicalAmbiguity($study)) {
            return false;
        }

        if (preg_match('/\b(?:papanicolaou|vph|virus\s+del\s+papiloma)\b/iu', $study->studyName)) {
            return false;
        }

        if ($this->studyHasCategory($study, LaboratoryInstructionCategory::URINE_SIMPLE)) {
            return true;
        }

        return (bool) preg_match(
            '/\bexamen\s+general\s+de\s+orina\b|\borina\s+simple\b|\burocultivo\b/iu',
            $study->studyName,
        );
    }

    private function studyIsUrine24h(LaboratoryInstructionParseStudyResult $study): bool
    {
        if ($this->studyHasCategory($study, LaboratoryInstructionCategory::URINE_24H)) {
            return true;
        }

        return (bool) preg_match(
            '/\borina\s+(?:de\s+|durante\s+)?24\s*horas?\b/iu',
            $study->studyName.' '.$study->sourceText,
        );
    }

    private function studyIsStool(LaboratoryInstructionParseStudyResult $study): bool
    {
        if ($this->studyHasCategory($study, LaboratoryInstructionCategory::STOOL)) {
            return true;
        }

        return (bool) preg_match(
            '/\b(?:copro|heces|materia\s+fecal)\b/iu',
            $study->studyName.' '.$study->sourceText,
        );
    }

    private function studyIsSemen(LaboratoryInstructionParseStudyResult $study): bool
    {
        if ($this->studyHasCategory($study, LaboratoryInstructionCategory::SEMEN)) {
            return true;
        }

        return (bool) preg_match(
            '/\b(?:espermograma|espermatobioscopia|semen)\b/iu',
            $study->studyName.' '.$study->sourceText,
        );
    }

    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     */
    private function orderHasUrineSimpleStudy(array $studies): bool
    {
        foreach ($studies as $study) {
            if ($this->studyIsUrineSimple($study) && ! $this->studyIsUrine24h($study)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     */
    private function orderHasDistinctUrineSimpleStudy(array $studies): bool
    {
        foreach ($studies as $study) {
            if ($this->studyIsUrineSimple($study) && ! $this->studyIsUrine24h($study)) {
                return true;
            }
        }

        return false;
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
            'rules' => $rules,
            'blocks' => [],
        ];
    }
}
