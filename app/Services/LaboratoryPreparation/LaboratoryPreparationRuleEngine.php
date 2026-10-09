<?php

namespace App\Services\LaboratoryPreparation;

use App\Services\LaboratoryPreparation\Compatibility\AgeConditionResolver;
use App\Services\LaboratoryPreparation\Compatibility\FastingHourInterval;
use App\Services\LaboratoryPreparation\Compatibility\FastingRestrictionCollector;
use App\Services\LaboratoryPreparation\Compatibility\GeneralPreparationCompatibilityEvaluator;
use App\Services\LaboratoryPreparation\Compatibility\HydrationCompatibilityEvaluator;
use App\Services\LaboratoryPreparation\Compatibility\OperationalCompatibilityEvaluator;
use App\Services\LaboratoryPreparation\Compatibility\SampleCompatibilityEvaluator;
use App\Services\LaboratoryPreparation\Compatibility\UninterpretedInstructionGuard;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionCategory;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionParseResult;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionParseStudyResult;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionRecognitionStatus;
use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionRequirement;

class LaboratoryPreparationRuleEngine
{
    public const RULES_VERSION = 'famedic-indicaciones-v3';

    /** @var list<string> */
    public const PHASE_3A_RULES = ['R01', 'R02', 'R03'];

    /** @var list<string> */
    public const PHASE_3B_RULES = ['R04', 'R05', 'R06', 'R07', 'R08'];

    /** @var list<string> */
    public const PHASE_3C_RULES = ['R09', 'R10', 'R11', 'R12', 'R13', 'R14'];

    /** @var list<string> */
    public const PHASE_3D_RULES = ['R15', 'R16', 'R17', 'R18'];

    /** @var list<string> */
    private const PHASE_3A_CATEGORIES = [
        LaboratoryInstructionCategory::FASTING,
        LaboratoryInstructionCategory::AGE_CONDITION,
        LaboratoryInstructionCategory::HYDRATION,
        LaboratoryInstructionCategory::NO_PREPARATION,
        LaboratoryInstructionCategory::SAMPLE_TIMING,
    ];

    public function __construct(
        private readonly AgeConditionResolver $ageConditionResolver = new AgeConditionResolver,
        private readonly HydrationCompatibilityEvaluator $hydrationEvaluator = new HydrationCompatibilityEvaluator,
        private readonly FastingRestrictionCollector $fastingRestrictionCollector = new FastingRestrictionCollector,
        private readonly SampleCompatibilityEvaluator $sampleEvaluator = new SampleCompatibilityEvaluator,
        private readonly OperationalCompatibilityEvaluator $operationalEvaluator = new OperationalCompatibilityEvaluator,
        private readonly GeneralPreparationCompatibilityEvaluator $generalPreparationEvaluator = new GeneralPreparationCompatibilityEvaluator,
        private readonly UninterpretedInstructionGuard $uninterpretedInstructionGuard = new UninterpretedInstructionGuard,
    ) {}

    /**
     * @param  bool  $restrictToPhase3aCategories  Si es true, cualquier requisito fuera de R01–R03 provoca fallback conservador.
     */
    public function evaluate(
        string $orderId,
        LaboratoryInstructionParseResult $parseResult,
        LaboratoryPreparationPatientContext $patient,
        bool $restrictToPhase3aCategories = true,
    ): LaboratoryPreparationDecision {
        $originalInstructions = $this->buildOriginalInstructions($parseResult->studies);
        $rulesApplied = [];
        $phase = $restrictToPhase3aCategories ? '3A' : '3D';
        $trace = [
            'engine' => 'LaboratoryPreparationRuleEngine',
            'phase' => $phase,
            'rules_enabled' => $restrictToPhase3aCategories
                ? self::PHASE_3A_RULES
                : [...self::PHASE_3A_RULES, ...self::PHASE_3B_RULES, ...self::PHASE_3C_RULES, ...self::PHASE_3D_RULES],
        ];

        $qualityFallback = $this->detectSourceQualityIssues(
            $parseResult->studies,
            deferUnknownUnit: ! $restrictToPhase3aCategories,
        );
        if ($qualityFallback !== null) {
            return $this->fallback(
                orderId: $orderId,
                rulesApplied: $rulesApplied,
                reason: $qualityFallback['reason'],
                category: LaboratoryPreparationDecision::FALLBACK_SOURCE_QUALITY,
                originalInstructions: $originalInstructions,
                trace: array_merge($trace, $qualityFallback['trace']),
            );
        }

        if ($restrictToPhase3aCategories) {
            $outOfScope = $this->detectOutOfScopeCategories($parseResult->studies);
            if ($outOfScope !== []) {
                return $this->fallback(
                    orderId: $orderId,
                    rulesApplied: $rulesApplied,
                    reason: 'rules_outside_phase_3a_scope',
                    category: LaboratoryPreparationDecision::FALLBACK_FUNCTIONAL_AMBIGUITY,
                    originalInstructions: $originalInstructions,
                    trace: array_merge($trace, ['out_of_scope_categories' => $outOfScope]),
                );
            }
        }

        $ageResolution = $this->ageConditionResolver->resolveFastingIntervals($parseResult->studies, $patient);
        if ($ageResolution['fallback']) {
            return $this->fallback(
                orderId: $orderId,
                rulesApplied: ['R02'],
                reason: (string) $ageResolution['reason'],
                category: LaboratoryPreparationDecision::FALLBACK_FUNCTIONAL_AMBIGUITY,
                originalInstructions: $originalInstructions,
                trace: array_merge($trace, ['age_resolution' => $ageResolution['trace']]),
            );
        }

        /** @var list<FastingHourInterval> $fastingIntervals */
        $fastingIntervals = $ageResolution['intervals'];
        $consolidated = [
            'age_resolution' => $ageResolution['trace'],
        ];

        $usedAgeRule = $this->usedAgeRule($parseResult->studies, $ageResolution['trace']);

        if ($fastingIntervals !== []) {
            if ($usedAgeRule) {
                $rulesApplied[] = 'R02';
            }
            $rulesApplied[] = 'R01';

            $consolidatedFasting = $this->consolidateFastingIntervals($fastingIntervals);
            if ($consolidatedFasting === null) {
                return $this->fallback(
                    orderId: $orderId,
                    rulesApplied: array_values(array_unique(['R01', 'R02'])),
                    reason: 'incompatible_fasting_interval',
                    category: LaboratoryPreparationDecision::FALLBACK_CLINICAL_CONFLICT,
                    originalInstructions: $originalInstructions,
                    trace: array_merge($trace, [
                        'fasting_intervals' => array_map(
                            fn (FastingHourInterval $interval) => $interval->toConsolidatedShape(),
                            $fastingIntervals,
                        ),
                    ]),
                );
            }

            $consolidated['fasting'] = array_merge(
                $consolidatedFasting['shape'],
                [
                    'sources' => $consolidatedFasting['sources'],
                    'dietary_restrictions' => $consolidatedFasting['dietary_restrictions'],
                ],
            );
        }

        if ($restrictToPhase3aCategories) {
            $hydrationEvaluation = $this->hydrationEvaluator->evaluate($parseResult->studies);
            if ($hydrationEvaluation['fallback']) {
                $rulesApplied = array_values(array_unique([...$rulesApplied, 'R03']));

                return $this->fallback(
                    orderId: $orderId,
                    rulesApplied: $rulesApplied,
                    reason: (string) $hydrationEvaluation['reason'],
                    category: $this->mapFallbackCategory((string) $hydrationEvaluation['category']),
                    originalInstructions: $originalInstructions,
                    trace: array_merge($trace, ['hydration' => $hydrationEvaluation['trace']]),
                );
            }

            if ($hydrationEvaluation['hydration'] !== null) {
                $rulesApplied[] = 'R03';
                $consolidated['hydration'] = $hydrationEvaluation['hydration'];
            }
        }

        if (! $restrictToPhase3aCategories) {
            $sampleEvaluation = $this->sampleEvaluator->evaluate($parseResult->studies, $patient);
            if ($sampleEvaluation['fallback']) {
                $rulesApplied = array_values(array_unique([...$rulesApplied, ...$sampleEvaluation['rules']]));

                return $this->fallback(
                    orderId: $orderId,
                    rulesApplied: $rulesApplied,
                    reason: (string) $sampleEvaluation['reason'],
                    category: $this->mapFallbackCategory((string) $sampleEvaluation['category']),
                    originalInstructions: $originalInstructions,
                    trace: array_merge($trace, ['sample' => $sampleEvaluation['blocks']]),
                );
            }

            foreach ($sampleEvaluation['blocks'] as $key => $block) {
                $consolidated[$key] = $block;
            }

            $rulesApplied = array_values(array_unique([...$rulesApplied, ...$sampleEvaluation['rules']]));

            $operationalEvaluation = $this->operationalEvaluator->evaluate($parseResult->studies);
            if ($operationalEvaluation['fallback']) {
                $rulesApplied = array_values(array_unique([...$rulesApplied, ...$operationalEvaluation['rules']]));

                return $this->fallback(
                    orderId: $orderId,
                    rulesApplied: $rulesApplied,
                    reason: (string) $operationalEvaluation['reason'],
                    category: $this->mapFallbackCategory((string) $operationalEvaluation['category']),
                    originalInstructions: $originalInstructions,
                    trace: array_merge($trace, ['operational' => $operationalEvaluation['blocks']]),
                );
            }

            foreach ($operationalEvaluation['blocks'] as $key => $block) {
                $consolidated[$key] = $block;
            }

            $rulesApplied = array_values(array_unique([...$rulesApplied, ...$operationalEvaluation['rules']]));

            $generalPreparationEvaluation = $this->generalPreparationEvaluator->evaluate($parseResult->studies);
            if ($generalPreparationEvaluation['fallback']) {
                $rulesApplied = array_values(array_unique([...$rulesApplied, ...$generalPreparationEvaluation['rules']]));

                return $this->fallback(
                    orderId: $orderId,
                    rulesApplied: $rulesApplied,
                    reason: (string) $generalPreparationEvaluation['reason'],
                    category: $this->mapFallbackCategory((string) $generalPreparationEvaluation['category']),
                    originalInstructions: $originalInstructions,
                    trace: array_merge($trace, ['general_preparation' => $generalPreparationEvaluation['blocks']]),
                );
            }

            foreach ($generalPreparationEvaluation['blocks'] as $key => $block) {
                $consolidated[$key] = $block;
            }

            $rulesApplied = array_values(array_unique([...$rulesApplied, ...$generalPreparationEvaluation['rules']]));

            $hydrationEvaluation = $this->hydrationEvaluator->evaluate($parseResult->studies);
            if ($hydrationEvaluation['fallback']) {
                $rulesApplied = array_values(array_unique([...$rulesApplied, 'R03']));

                return $this->fallback(
                    orderId: $orderId,
                    rulesApplied: $rulesApplied,
                    reason: (string) $hydrationEvaluation['reason'],
                    category: $this->mapFallbackCategory((string) $hydrationEvaluation['category']),
                    originalInstructions: $originalInstructions,
                    trace: array_merge($trace, ['hydration' => $hydrationEvaluation['trace']]),
                );
            }

            if ($hydrationEvaluation['hydration'] !== null) {
                $rulesApplied[] = 'R03';
                $consolidated['hydration'] = $hydrationEvaluation['hydration'];
            }
        }

        $rulesApplied = array_values(array_unique($rulesApplied));

        if ($rulesApplied === []) {
            return $this->fallback(
                orderId: $orderId,
                rulesApplied: [],
                reason: 'no_phase_3a_requirements_detected',
                category: LaboratoryPreparationDecision::FALLBACK_FUNCTIONAL_AMBIGUITY,
                originalInstructions: $originalInstructions,
                trace: $trace,
            );
        }

        if (! $restrictToPhase3aCategories) {
            $uninterpreted = $this->uninterpretedInstructionGuard->detectBlockingContent($parseResult->studies);
            if ($uninterpreted !== null) {
                return $this->fallback(
                    orderId: $orderId,
                    rulesApplied: $rulesApplied,
                    reason: $uninterpreted['reason'],
                    category: LaboratoryPreparationDecision::FALLBACK_SOURCE_QUALITY,
                    originalInstructions: $originalInstructions,
                    trace: array_merge($trace, ['uninterpreted' => $uninterpreted['trace']]),
                );
            }
        }

        $consolidated['trace'] = $trace;

        return LaboratoryPreparationDecision::autoConsolidated(
            orderId: $orderId,
            rulesVersion: self::RULES_VERSION,
            rulesApplied: $rulesApplied,
            consolidatedRequirements: $consolidated,
            originalInstructions: $originalInstructions,
        );
    }

    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     * @return list<array{study_id: string|null, study_name: string, source_instructions: string}>
     */
    private function buildOriginalInstructions(array $studies): array
    {
        return array_map(
            fn (LaboratoryInstructionParseStudyResult $study) => [
                'study_id' => $study->studyId,
                'study_name' => $study->studyName,
                'source_instructions' => $study->sourceText,
            ],
            $studies,
        );
    }

    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     * @return array{reason: string, trace: array<string, mixed>}|null
     */
    private function detectSourceQualityIssues(array $studies, bool $deferUnknownUnit = false): ?array
    {
        foreach ($studies as $study) {
            foreach ($study->requirements as $requirement) {
                if ($requirement->requirementType === 'unrecognized_fragment') {
                    continue;
                }

                if ($requirement->recognitionStatus === LaboratoryInstructionRecognitionStatus::INCOMPLETE) {
                    return [
                        'reason' => 'incomplete_requirement',
                        'trace' => ['requirement_type' => $requirement->requirementType],
                    ];
                }

                if ($requirement->category === LaboratoryInstructionCategory::FASTING) {
                    $kind = (string) ($requirement->normalizedValue['kind'] ?? '');
                    if (in_array($kind, ['required', 'incomplete'], true)) {
                        return [
                            'reason' => 'fasting_hours_not_structured',
                            'trace' => ['requirement_type' => $requirement->requirementType],
                        ];
                    }
                }

                if ($requirement->requirementType === 'unknown_unit') {
                    if ($deferUnknownUnit) {
                        continue;
                    }

                    return [
                        'reason' => 'unknown_unit',
                        'trace' => ['source_span' => $requirement->sourceSpan],
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     * @return list<string>
     */
    private function detectOutOfScopeCategories(array $studies): array
    {
        $found = [];

        foreach ($studies as $study) {
            foreach ($study->requirements as $requirement) {
                if ($requirement->category === LaboratoryInstructionCategory::UNKNOWN) {
                    if ($requirement->requirementType === 'unrecognized_fragment') {
                        continue;
                    }
                }

                if (! in_array($requirement->category, self::PHASE_3A_CATEGORIES, true)) {
                    $found[] = $requirement->category;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @param  list<FastingHourInterval>  $intervals
     * @return array{shape: array<string, mixed>, sources: list<array<string, mixed>>, dietary_restrictions: list<array<string, mixed>>}|null
     */
    private function consolidateFastingIntervals(array $intervals): ?array
    {
        $current = $intervals[0];

        for ($index = 1; $index < count($intervals); $index++) {
            $intersection = $current->intersect($intervals[$index]);
            if ($intersection === null) {
                return null;
            }
            $current = $intersection;
        }

        $restrictions = $this->fastingRestrictionCollector->collectFromIntervals($intervals);
        $withRestrictions = array_values(array_filter(
            $intervals,
            fn (FastingHourInterval $interval) => ($interval->requirement->normalizedValue['dietary_restrictions'] ?? []) !== [],
        ));

        if (count($withRestrictions) > 1) {
            $first = $this->fastingRestrictionCollector->collectFromIntervals([$withRestrictions[0]]);
            for ($i = 1; $i < count($withRestrictions); $i++) {
                $next = $this->fastingRestrictionCollector->collectFromIntervals([$withRestrictions[$i]]);
                if (! $this->fastingRestrictionCollector->restrictionsCompatible($first, $next)) {
                    return null;
                }
            }
        }

        $sources = array_map(
            fn (FastingHourInterval $interval) => [
                'study_id' => $interval->requirement->studyId,
                'study_name' => $interval->requirement->studyName,
                'source_span' => $interval->requirement->sourceSpan,
                'normalized_value' => $interval->requirement->normalizedValue,
            ],
            $intervals,
        );

        return [
            'shape' => $current->toConsolidatedShape(),
            'sources' => $sources,
            'dietary_restrictions' => $restrictions,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $originalInstructions
     * @param  array<string, mixed>  $trace
     */
    private function fallback(
        string $orderId,
        array $rulesApplied,
        string $reason,
        string $category,
        array $originalInstructions,
        array $trace,
    ): LaboratoryPreparationDecision {
        return LaboratoryPreparationDecision::fallbackOriginal(
            orderId: $orderId,
            rulesVersion: self::RULES_VERSION,
            rulesApplied: $rulesApplied,
            fallbackReason: $reason,
            fallbackCategory: $category,
            needsProviderReview: true,
            originalInstructions: $originalInstructions,
        );
    }

    private function mapFallbackCategory(string $category): string
    {
        return match ($category) {
            'clinical_conflict' => LaboratoryPreparationDecision::FALLBACK_CLINICAL_CONFLICT,
            'source_quality' => LaboratoryPreparationDecision::FALLBACK_SOURCE_QUALITY,
            default => LaboratoryPreparationDecision::FALLBACK_FUNCTIONAL_AMBIGUITY,
        };
    }

    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     * @param  array<string, mixed>  $ageTrace
     */
    private function usedAgeRule(array $studies, array $ageTrace): bool
    {
        $hasAgeRequirements = collect($studies)->contains(
            fn (LaboratoryInstructionParseStudyResult $study) => collect($study->requirements)
                ->contains(fn (LaboratoryInstructionRequirement $requirement) => $requirement->category === LaboratoryInstructionCategory::AGE_CONDITION),
        );

        if ($hasAgeRequirements) {
            return true;
        }

        return ($ageTrace['excluded_requirements'] ?? []) !== [];
    }
}
