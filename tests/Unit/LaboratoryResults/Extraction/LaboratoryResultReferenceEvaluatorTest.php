<?php

namespace Tests\Unit\LaboratoryResults\Extraction;

use App\Enums\LaboratoryResultObservationValueType;
use App\Enums\LaboratoryResultReferenceStatus;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultReferenceEvaluation;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultReferenceEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryResultReferenceEvaluatorTest extends TestCase
{
    private LaboratoryResultReferenceEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = app(LaboratoryResultReferenceEvaluator::class);
    }

    #[Test]
    public function calcula_normal_legacy(): void
    {
        $status = $this->evaluator->evaluate(
            LaboratoryResultObservationValueType::Numeric,
            95,
            70,
            100,
        );

        $this->assertSame(LaboratoryResultReferenceStatus::Normal, $status);
    }

    #[Test]
    public function calcula_low_legacy(): void
    {
        $status = $this->evaluator->evaluate(
            LaboratoryResultObservationValueType::Numeric,
            65,
            70,
            100,
        );

        $this->assertSame(LaboratoryResultReferenceStatus::Low, $status);
    }

    #[Test]
    public function calcula_high_legacy(): void
    {
        $status = $this->evaluator->evaluate(
            LaboratoryResultObservationValueType::Numeric,
            110,
            70,
            100,
        );

        $this->assertSame(LaboratoryResultReferenceStatus::High, $status);
    }

    #[Test]
    public function calcula_unknown_sin_rango_legacy(): void
    {
        $status = $this->evaluator->evaluate(
            LaboratoryResultObservationValueType::Numeric,
            95,
            null,
            null,
        );

        $this->assertSame(LaboratoryResultReferenceStatus::Unknown, $status);
    }

    #[Test]
    public function cualitativo_es_not_applicable_legacy(): void
    {
        $status = $this->evaluator->evaluate(
            LaboratoryResultObservationValueType::Qualitative,
            null,
            null,
            null,
        );

        $this->assertSame(LaboratoryResultReferenceStatus::NotApplicable, $status);
    }

    #[Test]
    #[DataProvider('closedRangeGroundTruthProvider')]
    public function deterministic_rango_cerrado(float $value, string $reference, LaboratoryResultReferenceStatus $expected): void
    {
        $evaluation = $this->evaluateFromReferenceText($value, $reference);

        $this->assertTrue($evaluation->evaluated);
        $this->assertSame(LaboratoryResultReferenceEvaluation::METHOD, $evaluation->method());
        $this->assertSame('range', $evaluation->referenceKind);
        $this->assertSame($expected, $evaluation->status);
    }

    /**
     * @return list<array{0: float, 1: string, 2: LaboratoryResultReferenceStatus}>
     */
    public static function closedRangeGroundTruthProvider(): array
    {
        return [
            [69, '70-100', LaboratoryResultReferenceStatus::Low],
            [70, '70-100', LaboratoryResultReferenceStatus::Normal],
            [85, '70-100', LaboratoryResultReferenceStatus::Normal],
            [100, '70-100', LaboratoryResultReferenceStatus::Normal],
            [101, '70-100', LaboratoryResultReferenceStatus::High],
        ];
    }

    #[Test]
    public function deterministic_menor_que(): void
    {
        $this->assertSame(
            LaboratoryResultReferenceStatus::Normal,
            $this->evaluateFromReferenceText(149, '<150')->status,
        );
        $this->assertSame(
            LaboratoryResultReferenceStatus::High,
            $this->evaluateFromReferenceText(150, '<150')->status,
        );
    }

    #[Test]
    public function deterministic_mayor_que(): void
    {
        $this->assertSame(
            LaboratoryResultReferenceStatus::Low,
            $this->evaluateFromReferenceText(60, '>60')->status,
        );
        $this->assertSame(
            LaboratoryResultReferenceStatus::Normal,
            $this->evaluateFromReferenceText(61, '>60')->status,
        );
    }

    #[Test]
    public function deterministic_menor_o_igual(): void
    {
        $this->assertSame(
            LaboratoryResultReferenceStatus::Normal,
            $this->evaluateFromReferenceText(100, '<=100')->status,
        );
        $this->assertSame(
            LaboratoryResultReferenceStatus::High,
            $this->evaluateFromReferenceText(101, '<=100')->status,
        );
    }

    #[Test]
    public function deterministic_mayor_o_igual(): void
    {
        $this->assertSame(
            LaboratoryResultReferenceStatus::Normal,
            $this->evaluateFromReferenceText(10, '>=10')->status,
        );
        $this->assertSame(
            LaboratoryResultReferenceStatus::Low,
            $this->evaluateFromReferenceText(9.99, '>=10')->status,
        );
    }

    #[Test]
    public function deterministic_referencia_ausente_es_unknown(): void
    {
        $evaluation = $this->evaluator->evaluateDeterministic(
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 95,
            referenceText: null,
            referenceLow: null,
            referenceHigh: null,
        );

        $this->assertFalse($evaluation->evaluated);
        $this->assertSame(LaboratoryResultReferenceStatus::Unknown, $evaluation->status);
        $this->assertSame('reference_bounds_missing', $evaluation->reason);
    }

    #[Test]
    public function deterministic_valor_no_numerico_es_unknown(): void
    {
        $evaluation = $this->evaluator->evaluateDeterministic(
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: null,
            referenceText: '70-100',
            referenceLow: 70,
            referenceHigh: 100,
        );

        $this->assertFalse($evaluation->evaluated);
        $this->assertSame(LaboratoryResultReferenceStatus::Unknown, $evaluation->status);
    }

    #[Test]
    public function deterministic_cualitativo_es_not_applicable(): void
    {
        $evaluation = $this->evaluator->evaluateDeterministic(
            valueType: LaboratoryResultObservationValueType::Qualitative,
            numericValue: null,
            referenceText: 'NEGATIVO',
            referenceLow: null,
            referenceHigh: null,
        );

        $this->assertFalse($evaluation->evaluated);
        $this->assertSame(LaboratoryResultReferenceStatus::NotApplicable, $evaluation->status);
    }

    #[Test]
    public function deterministic_unidad_incompatible_es_unknown(): void
    {
        $evaluation = $this->evaluator->evaluateDeterministic(
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 4.6,
            referenceText: '3.9 - 5.4',
            referenceLow: 3.9,
            referenceHigh: 5.4,
            unit: 'pg',
            catalogUnit: 'g/dL',
        );

        $this->assertFalse($evaluation->evaluated);
        $this->assertSame(LaboratoryResultReferenceStatus::Unknown, $evaluation->status);
        $this->assertSame('incompatible_unit', $evaluation->reason);
    }

    #[Test]
    public function deterministic_referencia_no_interpretable_es_unknown(): void
    {
        $evaluation = $this->evaluator->evaluateDeterministic(
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 95,
            referenceText: 'DESEABLE',
            referenceLow: null,
            referenceHigh: null,
        );

        $this->assertFalse($evaluation->evaluated);
        $this->assertSame(LaboratoryResultReferenceStatus::Unknown, $evaluation->status);
    }

    private function evaluateFromReferenceText(float $value, string $referenceText): \App\Services\LaboratoryResults\Extraction\LaboratoryResultReferenceEvaluation
    {
        return $this->evaluator->evaluateDeterministic(
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: $value,
            referenceText: $referenceText,
            referenceLow: null,
            referenceHigh: null,
        );
    }
}
