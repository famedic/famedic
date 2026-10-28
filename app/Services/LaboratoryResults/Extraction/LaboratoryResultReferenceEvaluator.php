<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryResultObservationValueType;
use App\Enums\LaboratoryResultReferenceStatus;

final class LaboratoryResultReferenceEvaluator
{
    public function __construct(
        private readonly LaboratoryReferenceNormalizer $referenceNormalizer,
        private readonly LaboratoryUnitNormalizer $unitNormalizer,
    ) {}

    /**
     * Evaluación legacy para extracción Text (sin texto de referencia ni unidades).
     * Se conserva sin cambios de semántica para no alterar flujo publicado.
     */
    public function evaluate(
        LaboratoryResultObservationValueType $valueType,
        ?float $numericValue,
        ?float $referenceLow,
        ?float $referenceHigh,
    ): LaboratoryResultReferenceStatus {
        if ($valueType === LaboratoryResultObservationValueType::Qualitative) {
            return LaboratoryResultReferenceStatus::NotApplicable;
        }

        if ($valueType !== LaboratoryResultObservationValueType::Numeric || $numericValue === null) {
            return LaboratoryResultReferenceStatus::Unknown;
        }

        if ($referenceLow !== null && $numericValue < $referenceLow) {
            return LaboratoryResultReferenceStatus::Low;
        }

        if ($referenceHigh !== null && $numericValue > $referenceHigh) {
            return LaboratoryResultReferenceStatus::High;
        }

        if ($referenceLow !== null && $referenceHigh !== null) {
            if ($numericValue >= $referenceLow && $numericValue <= $referenceHigh) {
                return LaboratoryResultReferenceStatus::Normal;
            }

            return LaboratoryResultReferenceStatus::Unknown;
        }

        if ($referenceLow !== null || $referenceHigh !== null) {
            return LaboratoryResultReferenceStatus::Normal;
        }

        return LaboratoryResultReferenceStatus::Unknown;
    }

    public function evaluateDeterministic(
        LaboratoryResultObservationValueType $valueType,
        ?float $numericValue,
        ?string $referenceText,
        ?float $referenceLow,
        ?float $referenceHigh,
        ?string $unit = null,
        ?string $catalogUnit = null,
    ): LaboratoryResultReferenceEvaluation {
        if ($valueType === LaboratoryResultObservationValueType::Qualitative) {
            return new LaboratoryResultReferenceEvaluation(
                status: LaboratoryResultReferenceStatus::NotApplicable,
                evaluated: false,
                reason: 'qualitative_value',
                referenceKind: 'not_applicable',
                referenceLowUsed: null,
                referenceHighUsed: null,
            );
        }

        if ($valueType === LaboratoryResultObservationValueType::Comment) {
            return new LaboratoryResultReferenceEvaluation(
                status: LaboratoryResultReferenceStatus::NotApplicable,
                evaluated: false,
                reason: 'comment_value',
                referenceKind: 'not_applicable',
                referenceLowUsed: null,
                referenceHighUsed: null,
            );
        }

        if ($valueType !== LaboratoryResultObservationValueType::Numeric || $numericValue === null) {
            return new LaboratoryResultReferenceEvaluation(
                status: LaboratoryResultReferenceStatus::Unknown,
                evaluated: false,
                reason: 'non_numeric_value',
                referenceKind: 'unknown',
                referenceLowUsed: null,
                referenceHighUsed: null,
            );
        }

        if ($unit !== null && $catalogUnit !== null && ! $this->unitNormalizer->areEquivalent($unit, $catalogUnit)) {
            return new LaboratoryResultReferenceEvaluation(
                status: LaboratoryResultReferenceStatus::Unknown,
                evaluated: false,
                reason: 'incompatible_unit',
                referenceKind: 'unknown',
                referenceLowUsed: null,
                referenceHighUsed: null,
            );
        }

        if ($referenceText === null || trim($referenceText) === '') {
            if ($referenceLow === null && $referenceHigh === null) {
                return new LaboratoryResultReferenceEvaluation(
                    status: LaboratoryResultReferenceStatus::Unknown,
                    evaluated: false,
                    reason: 'reference_bounds_missing',
                    referenceKind: 'empty',
                    referenceLowUsed: null,
                    referenceHighUsed: null,
                );
            }
        }

        $parsed = $this->referenceNormalizer->parse($referenceText);
        $low = $referenceLow ?? $parsed->referenceLow;
        $high = $referenceHigh ?? $parsed->referenceHigh;
        $kind = $this->resolveReferenceKind($referenceText, $parsed, $low, $high);

        if ($low === null && $high === null) {
            return new LaboratoryResultReferenceEvaluation(
                status: LaboratoryResultReferenceStatus::Unknown,
                evaluated: false,
                reason: 'reference_bounds_missing',
                referenceKind: $kind,
                referenceLowUsed: null,
                referenceHighUsed: null,
            );
        }

        if ($kind === 'unknown' || $kind === 'empty') {
            return new LaboratoryResultReferenceEvaluation(
                status: LaboratoryResultReferenceStatus::Unknown,
                evaluated: false,
                reason: 'reference_not_interpretable',
                referenceKind: $kind,
                referenceLowUsed: $low,
                referenceHighUsed: $high,
            );
        }

        $status = match ($kind) {
            'range' => $this->evaluateClosedRange($numericValue, $low, $high),
            'lt' => $this->evaluateStrictLessThan($numericValue, $high),
            'lte' => $this->evaluateLessThanOrEqual($numericValue, $high),
            'gt' => $this->evaluateStrictGreaterThan($numericValue, $low),
            'gte' => $this->evaluateGreaterThanOrEqual($numericValue, $low),
            default => LaboratoryResultReferenceStatus::Unknown,
        };

        if ($status === LaboratoryResultReferenceStatus::Unknown) {
            return new LaboratoryResultReferenceEvaluation(
                status: $status,
                evaluated: false,
                reason: 'insufficient_bounds_for_kind',
                referenceKind: $kind,
                referenceLowUsed: $low,
                referenceHighUsed: $high,
            );
        }

        return new LaboratoryResultReferenceEvaluation(
            status: $status,
            evaluated: true,
            reason: $kind,
            referenceKind: $kind,
            referenceLowUsed: $low,
            referenceHighUsed: $high,
        );
    }

    private function evaluateClosedRange(float $value, ?float $low, ?float $high): LaboratoryResultReferenceStatus
    {
        if ($low === null || $high === null) {
            return LaboratoryResultReferenceStatus::Unknown;
        }

        if ($value < $low) {
            return LaboratoryResultReferenceStatus::Low;
        }

        if ($value > $high) {
            return LaboratoryResultReferenceStatus::High;
        }

        return LaboratoryResultReferenceStatus::Normal;
    }

    private function evaluateStrictLessThan(float $value, ?float $high): LaboratoryResultReferenceStatus
    {
        if ($high === null) {
            return LaboratoryResultReferenceStatus::Unknown;
        }

        if ($value < $high) {
            return LaboratoryResultReferenceStatus::Normal;
        }

        return LaboratoryResultReferenceStatus::High;
    }

    private function evaluateLessThanOrEqual(float $value, ?float $high): LaboratoryResultReferenceStatus
    {
        if ($high === null) {
            return LaboratoryResultReferenceStatus::Unknown;
        }

        if ($value <= $high) {
            return LaboratoryResultReferenceStatus::Normal;
        }

        return LaboratoryResultReferenceStatus::High;
    }

    private function evaluateStrictGreaterThan(float $value, ?float $low): LaboratoryResultReferenceStatus
    {
        if ($low === null) {
            return LaboratoryResultReferenceStatus::Unknown;
        }

        if ($value > $low) {
            return LaboratoryResultReferenceStatus::Normal;
        }

        return LaboratoryResultReferenceStatus::Low;
    }

    private function evaluateGreaterThanOrEqual(float $value, ?float $low): LaboratoryResultReferenceStatus
    {
        if ($low === null) {
            return LaboratoryResultReferenceStatus::Unknown;
        }

        if ($value >= $low) {
            return LaboratoryResultReferenceStatus::Normal;
        }

        return LaboratoryResultReferenceStatus::Low;
    }

    private function resolveReferenceKind(
        ?string $referenceText,
        LaboratoryReferenceParseResult $parsed,
        ?float $low,
        ?float $high,
    ): string {
        $normalized = $this->referenceNormalizer->normalizeText($referenceText);

        if ($normalized !== null) {
            if (preg_match('/^([\d]+(?:[.,]\d+)?)\s*[-–—]\s*([\d]+(?:[.,]\d+)?)$/u', $normalized)) {
                return 'range';
            }

            if (preg_match('/^<=/u', $normalized)) {
                return 'lte';
            }

            if (preg_match('/^</u', $normalized)) {
                return 'lt';
            }

            if (preg_match('/^>=/u', $normalized)) {
                return 'gte';
            }

            if (preg_match('/^>/u', $normalized)) {
                return 'gt';
            }
        }

        if ($low !== null && $high !== null) {
            return 'range';
        }

        if ($high !== null && $low === null) {
            return 'lt';
        }

        if ($low !== null && $high === null) {
            return 'gt';
        }

        return match ($parsed->kind) {
            'range' => 'range',
            'lt', 'lte', 'gt', 'gte' => $parsed->kind,
            'empty' => 'empty',
            default => 'unknown',
        };
    }
}
