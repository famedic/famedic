<?php

namespace Tests\Unit\LaboratoryResults\Extraction;

use App\Enums\LaboratoryResultObservationValueType;
use App\Models\LaboratoryAnalyte;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultExtractionValidator;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultObservationCandidate;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Laboratory\StructuredResultsIsolatedSchema;
use Tests\TestCase;

class LaboratoryResultExtractionValidatorTest extends TestCase
{
    use StructuredResultsIsolatedSchema;

    private LaboratoryResultExtractionValidator $validator;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();
        $this->bootstrapStructuredResultsSchema();
        $this->validator = app(LaboratoryResultExtractionValidator::class);
    }

    protected function tearDown(): void
    {
        $this->tearDownStructuredResultsSchema();
        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function acepta_observation_valida(): void
    {
        $analyte = LaboratoryAnalyte::factory()->create();
        $candidate = new LaboratoryResultObservationCandidate(
            analyteNameRaw: 'Glucosa',
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 95.0,
            unit: 'mg/dL',
            referenceLow: 70,
            referenceHigh: 100,
        );

        $result = $this->validator->validateCandidate($candidate, $analyte);

        $this->assertTrue($result['valid']);
    }

    #[Test]
    public function rechaza_sin_valor(): void
    {
        $analyte = LaboratoryAnalyte::factory()->create();
        $candidate = new LaboratoryResultObservationCandidate(
            analyteNameRaw: 'Glucosa',
            valueType: LaboratoryResultObservationValueType::Numeric,
        );

        $result = $this->validator->validateCandidate($candidate, $analyte);

        $this->assertFalse($result['valid']);
        $this->assertContains('missing_numeric_value', $result['errors']);
    }

    #[Test]
    public function rechaza_low_mayor_que_high(): void
    {
        $analyte = LaboratoryAnalyte::factory()->create();
        $candidate = new LaboratoryResultObservationCandidate(
            analyteNameRaw: 'Glucosa',
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 95.0,
            referenceLow: 120,
            referenceHigh: 70,
        );

        $result = $this->validator->validateCandidate($candidate, $analyte);

        $this->assertFalse($result['valid']);
        $this->assertContains('reference_low_greater_than_high', $result['errors']);
    }

    #[Test]
    public function permite_unit_y_reference_null(): void
    {
        $analyte = LaboratoryAnalyte::factory()->create();
        $candidate = new LaboratoryResultObservationCandidate(
            analyteNameRaw: 'Glucosa',
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 95.0,
        );

        $result = $this->validator->validateCandidate($candidate, $analyte);

        $this->assertTrue($result['valid']);
    }
}
