<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryAnalyteResolutionStatus;
use App\Enums\LaboratoryReferenceComparisonOutcome;
use App\Enums\LaboratoryResultExtractionComparisonOutcome;
use App\Enums\LaboratoryResultObservationValueType;

class LaboratoryResultExtractionComparator
{
    private const NUMERIC_EPSILON = 0.001;

    public function __construct(
        private readonly LaboratoryAnalyteResolver $analyteResolver,
        private readonly LaboratoryUnitNormalizer $unitNormalizer,
        private readonly LaboratoryReferenceNormalizer $referenceNormalizer,
        private readonly LaboratoryObservationSourcePriorityResolver $sourcePriorityResolver,
    ) {}

    /**
     * @param  list<LaboratoryResultObservationCandidate>  $textCandidates
     * @param  list<LaboratoryResultObservationCandidate>  $visionCandidates
     */
    public function compare(array $textCandidates, array $visionCandidates): LaboratoryResultExtractionComparisonSummary
    {
        $textByKey = $this->indexCandidates($textCandidates);
        $visionByKey = $this->indexCandidates($visionCandidates);

        $items = [];
        $counts = [
            LaboratoryResultExtractionComparisonOutcome::Match->value => 0,
            LaboratoryResultExtractionComparisonOutcome::Conflict->value => 0,
            LaboratoryResultExtractionComparisonOutcome::VisionOnly->value => 0,
            LaboratoryResultExtractionComparisonOutcome::TextOnly->value => 0,
            LaboratoryResultExtractionComparisonOutcome::Unresolved->value => 0,
        ];

        $allKeys = array_values(array_unique(array_merge(array_keys($textByKey), array_keys($visionByKey))));

        foreach ($allKeys as $key) {
            $textEntry = $textByKey[$key] ?? null;
            $visionEntry = $visionByKey[$key] ?? null;
            $text = $textEntry['candidate'] ?? null;
            $vision = $visionEntry['candidate'] ?? null;

            if ($text !== null && $vision !== null) {
                [$outcome, $conflictFields, $referenceComparison] = $this->comparePair($text, $vision);
            } elseif ($text !== null) {
                $outcome = LaboratoryResultExtractionComparisonOutcome::TextOnly;
                $conflictFields = [];
                $referenceComparison = LaboratoryReferenceComparisonOutcome::Unknown;
            } elseif ($vision !== null) {
                $outcome = LaboratoryResultExtractionComparisonOutcome::VisionOnly;
                $conflictFields = [];
                $referenceComparison = LaboratoryReferenceComparisonOutcome::Unknown;
            } else {
                continue;
            }

            if (
                $textEntry !== null
                && $visionEntry !== null
                && (
                    $textEntry['resolution']->status === LaboratoryAnalyteResolutionStatus::Ambiguous
                    || $visionEntry['resolution']->status === LaboratoryAnalyteResolutionStatus::Ambiguous
                )
            ) {
                $outcome = LaboratoryResultExtractionComparisonOutcome::Unresolved;
                $conflictFields = ['analyte_identity_ambiguous'];
                $referenceComparison = LaboratoryReferenceComparisonOutcome::Unknown;
            }

            $textResolution = $textEntry['resolution'] ?? null;
            $visionResolution = $visionEntry['resolution'] ?? null;

            $baseItem = new LaboratoryResultExtractionComparisonItem(
                outcome: $outcome,
                analyteKey: $key,
                textCandidate: $text,
                visionCandidate: $vision,
                conflictFields: $conflictFields,
            );

            $item = new LaboratoryResultExtractionComparisonItem(
                outcome: $outcome,
                analyteKey: $key,
                textCandidate: $text,
                visionCandidate: $vision,
                conflictFields: $conflictFields,
                analyteCode: $textResolution?->analyteCode() ?? $visionResolution?->analyteCode(),
                textResolutionStatus: $textResolution?->status ?? LaboratoryAnalyteResolutionStatus::Unresolved,
                visionResolutionStatus: $visionResolution?->status ?? LaboratoryAnalyteResolutionStatus::Unresolved,
                sourcePriority: $this->sourcePriorityResolver->resolve($baseItem),
                requiresReview: $this->sourcePriorityResolver->requiresReview($baseItem),
                unitEquivalenceKeyText: $text !== null ? $this->unitNormalizer->equivalenceKey($text->unit) : null,
                unitEquivalenceKeyVision: $vision !== null ? $this->unitNormalizer->equivalenceKey($vision->unit) : null,
                referenceComparison: $referenceComparison,
            );

            $items[] = $item;
            $counts[$outcome->value]++;
        }

        return new LaboratoryResultExtractionComparisonSummary(
            items: $items,
            matchCount: $counts[LaboratoryResultExtractionComparisonOutcome::Match->value],
            conflictCount: $counts[LaboratoryResultExtractionComparisonOutcome::Conflict->value],
            visionOnlyCount: $counts[LaboratoryResultExtractionComparisonOutcome::VisionOnly->value],
            textOnlyCount: $counts[LaboratoryResultExtractionComparisonOutcome::TextOnly->value],
            unresolvedCount: $counts[LaboratoryResultExtractionComparisonOutcome::Unresolved->value],
        );
    }

    /**
     * @param  list<LaboratoryResultObservationCandidate>  $candidates
     * @return array<string, array{candidate: LaboratoryResultObservationCandidate, resolution: LaboratoryAnalyteResolutionResult}>
     */
    private function indexCandidates(array $candidates): array
    {
        $indexed = [];

        foreach ($candidates as $candidate) {
            $resolution = $this->analyteResolver->resolveDetailed($candidate->analyteNameRaw);
            $key = $resolution->identityKey;

            if ($key === null || $key === '') {
                continue;
            }

            if (! isset($indexed[$key])) {
                $indexed[$key] = [
                    'candidate' => $candidate,
                    'resolution' => $resolution,
                ];

                continue;
            }

            $indexed[$key] = $this->preferDuplicateIdentityCandidate(
                $indexed[$key],
                $candidate,
                $resolution,
            );
        }

        return $indexed;
    }

    /**
     * GDA differential tables may yield multiple observations mapped to the same
     * analyte code (e.g. NEUTROFILOS TOTALES % and miles/uL). Prefer the unit
     * aligned with the catalog default when disambiguating indexed candidates.
     *
     * @param  array{candidate: LaboratoryResultObservationCandidate, resolution: LaboratoryAnalyteResolutionResult}  $existing
     * @return array{candidate: LaboratoryResultObservationCandidate, resolution: LaboratoryAnalyteResolutionResult}
     */
    private function preferDuplicateIdentityCandidate(
        array $existing,
        LaboratoryResultObservationCandidate $incoming,
        LaboratoryAnalyteResolutionResult $incomingResolution,
    ): array {
        $preferred = $this->selectPreferredDuplicateCandidate(
            $existing['candidate'],
            $incoming,
            $existing['resolution']->analyte?->default_unit,
        );

        if ($preferred === $incoming) {
            return [
                'candidate' => $incoming,
                'resolution' => $incomingResolution,
            ];
        }

        return $existing;
    }

    private function selectPreferredDuplicateCandidate(
        LaboratoryResultObservationCandidate $existing,
        LaboratoryResultObservationCandidate $incoming,
        ?string $catalogDefaultUnit,
    ): LaboratoryResultObservationCandidate {
        if ($catalogDefaultUnit !== null && trim($catalogDefaultUnit) !== '') {
            $defaultKey = $this->unitNormalizer->equivalenceKey($catalogDefaultUnit);
            $existingMatchesDefault = $this->unitNormalizer->equivalenceKey($existing->unit) === $defaultKey;
            $incomingMatchesDefault = $this->unitNormalizer->equivalenceKey($incoming->unit) === $defaultKey;

            if ($incomingMatchesDefault && ! $existingMatchesDefault) {
                return $incoming;
            }

            if ($existingMatchesDefault && ! $incomingMatchesDefault) {
                return $existing;
            }
        }

        return $incoming->confidence > $existing->confidence ? $incoming : $existing;
    }

    /**
     * @return array{0: LaboratoryResultExtractionComparisonOutcome, 1: list<string>, 2: LaboratoryReferenceComparisonOutcome}
     */
    private function comparePair(
        LaboratoryResultObservationCandidate $text,
        LaboratoryResultObservationCandidate $vision,
    ): array {
        $conflictFields = [];

        if ($text->valueType !== $vision->valueType) {
            $conflictFields[] = 'value_type';
        }

        if ($text->valueType === LaboratoryResultObservationValueType::Numeric) {
            if (! $this->numericEquals($text->numericValue, $vision->numericValue)) {
                $conflictFields[] = 'numeric_value';
            }
        } elseif (! $this->textEquals($text->textValue, $vision->textValue)) {
            $conflictFields[] = 'text_value';
        }

        if (! $this->unitNormalizer->areEquivalent($text->unit, $vision->unit)) {
            $conflictFields[] = 'unit';
        }

        $referenceComparison = $this->referenceNormalizer->compare(
            textReferenceText: $text->referenceText,
            textReferenceLow: $text->referenceLow,
            textReferenceHigh: $text->referenceHigh,
            visionReferenceText: $vision->referenceText,
            visionReferenceLow: $vision->referenceLow,
            visionReferenceHigh: $vision->referenceHigh,
        );

        if ($referenceComparison === LaboratoryReferenceComparisonOutcome::Conflict) {
            if (! $this->numericEquals($text->referenceLow, $vision->referenceLow)) {
                $conflictFields[] = 'reference_low';
            }

            if (! $this->numericEquals($text->referenceHigh, $vision->referenceHigh)) {
                $conflictFields[] = 'reference_high';
            }

            if (! $this->textEquals(
                $this->referenceNormalizer->normalizeText($text->referenceText),
                $this->referenceNormalizer->normalizeText($vision->referenceText),
            )) {
                $conflictFields[] = 'reference_text';
            }
        }

        if ($conflictFields !== []) {
            return [LaboratoryResultExtractionComparisonOutcome::Conflict, $conflictFields, $referenceComparison];
        }

        if ($text->valueType === LaboratoryResultObservationValueType::Numeric && $text->numericValue === null && $vision->numericValue === null) {
            return [LaboratoryResultExtractionComparisonOutcome::Unresolved, [], $referenceComparison];
        }

        return [LaboratoryResultExtractionComparisonOutcome::Match, [], $referenceComparison];
    }

    private function numericEquals(?float $left, ?float $right): bool
    {
        if ($left === null && $right === null) {
            return true;
        }

        if ($left === null || $right === null) {
            return false;
        }

        return abs($left - $right) <= self::NUMERIC_EPSILON;
    }

    private function textEquals(?string $left, ?string $right): bool
    {
        $normalizedLeft = $this->referenceNormalizer->normalizeText($left);
        $normalizedRight = $this->referenceNormalizer->normalizeText($right);

        return $normalizedLeft === $normalizedRight;
    }
}
