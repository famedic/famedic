<?php

namespace Tests\Unit\LaboratoryResults;

use App\Enums\LaboratoryResultObservationValueType;
use App\Services\LaboratoryResults\Extraction\LaboratoryAnalyteResolver;
use App\Services\LaboratoryResults\Extraction\LaboratoryObservationSourcePriorityResolver;
use App\Services\LaboratoryResults\Extraction\LaboratoryReferenceNormalizer;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultExtractionComparisonReport;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultExtractionComparisonSummary;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultExtractionComparator;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultObservationCandidate;
use App\Services\LaboratoryResults\Extraction\LaboratoryUnitNormalizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryResultExtractionComparisonReportTest extends TestCase
{
    private LaboratoryResultExtractionComparator $comparator;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();

        Schema::dropIfExists('laboratory_analyte_aliases');
        Schema::dropIfExists('laboratory_analytes');

        Schema::create('laboratory_analytes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('canonical_name');
            $table->string('loinc_code', 40)->nullable()->unique();
            $table->string('default_unit', 40)->nullable();
            $table->string('value_kind', 20);
            $table->string('category', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('laboratory_analyte_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_analyte_id')->constrained('laboratory_analytes')->cascadeOnDelete();
            $table->string('alias_normalized', 191)->unique();
            $table->string('alias_raw')->nullable();
            $table->string('source', 20);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->timestamps();
        });

        $this->comparator = new LaboratoryResultExtractionComparator(
            analyteResolver: new LaboratoryAnalyteResolver,
            unitNormalizer: new LaboratoryUnitNormalizer,
            referenceNormalizer: new LaboratoryReferenceNormalizer,
            sourcePriorityResolver: new LaboratoryObservationSourcePriorityResolver,
        );
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('laboratory_analyte_aliases');
        Schema::dropIfExists('laboratory_analytes');
        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function calcula_match_y_conflict_rate_correctamente(): void
    {
        $text = [
            new LaboratoryResultObservationCandidate('Glucosa', LaboratoryResultObservationValueType::Numeric, 95.0, unit: 'mg/dL'),
            new LaboratoryResultObservationCandidate('Urea', LaboratoryResultObservationValueType::Numeric, 38.0, unit: 'mg/dL'),
        ];
        $vision = [
            new LaboratoryResultObservationCandidate('Glucosa', LaboratoryResultObservationValueType::Numeric, 9.5, unit: 'mg/dL'),
            new LaboratoryResultObservationCandidate('Urea', LaboratoryResultObservationValueType::Numeric, 38.0, unit: 'mg/dL'),
        ];

        $comparison = $this->comparator->compare($text, $vision);
        $report = new LaboratoryResultExtractionComparisonReport(
            comparison: $comparison,
            textObservationCount: 2,
            visionObservationCount: 2,
            visionExecuted: true,
            shadowMode: true,
        );

        $this->assertSame(1, $comparison->matchCount);
        $this->assertSame(1, $comparison->conflictCount);
        $this->assertSame(0.5, $report->matchRate());
        $this->assertSame(0.5, $report->conflictRate());
    }

    #[Test]
    public function calcula_vision_coverage_y_vision_only_rate(): void
    {
        $text = [
            new LaboratoryResultObservationCandidate('Glucosa', LaboratoryResultObservationValueType::Numeric, 95.0, unit: 'mg/dL'),
        ];
        $vision = [
            new LaboratoryResultObservationCandidate('Glucosa', LaboratoryResultObservationValueType::Numeric, 95.0, unit: 'mg/dL'),
            new LaboratoryResultObservationCandidate('Hemoglobina', LaboratoryResultObservationValueType::Numeric, 14.2, unit: 'g/dL'),
        ];

        $comparison = $this->comparator->compare($text, $vision);
        $report = new LaboratoryResultExtractionComparisonReport(
            comparison: $comparison,
            textObservationCount: 1,
            visionObservationCount: 2,
            visionExecuted: true,
            shadowMode: true,
        );

        $this->assertSame(2.0, $report->visionCoverage());
        $this->assertSame(0.5, $report->visionOnlyRate());
        $this->assertSame(0.0, $report->textOnlyRate());
    }

    #[Test]
    public function summary_array_contiene_campos_requeridos(): void
    {
        $summary = new LaboratoryResultExtractionComparisonSummary([], 0, 0, 0, 0, 0);
        $report = new LaboratoryResultExtractionComparisonReport(
            comparison: $summary,
            textObservationCount: 8,
            visionObservationCount: 8,
            visionExecuted: true,
            shadowMode: true,
        );

        $array = $report->toSummaryArray();

        $this->assertSame(8, $array['text_observations']);
        $this->assertSame(8, $array['vision_observations']);
        $this->assertArrayHasKey('match', $array);
        $this->assertArrayHasKey('conflict', $array);
        $this->assertArrayHasKey('vision_only', $array);
        $this->assertArrayHasKey('text_only', $array);
        $this->assertArrayHasKey('unresolved', $array);
        $this->assertTrue($array['shadow_mode']);
    }
}
