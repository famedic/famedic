<?php

namespace Tests\Unit\LaboratoryResults;

use App\Enums\LaboratoryObservationSourcePriority;
use App\Enums\LaboratoryReferenceComparisonOutcome;
use App\Enums\LaboratoryResultExtractionComparisonOutcome;
use App\Enums\LaboratoryResultObservationValueType;
use App\Services\LaboratoryResults\Extraction\LaboratoryAnalyteResolver;
use App\Services\LaboratoryResults\Extraction\LaboratoryObservationSourcePriorityResolver;
use App\Services\LaboratoryResults\Extraction\LaboratoryReferenceNormalizer;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultExtractionComparator;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultObservationCandidate;
use App\Services\LaboratoryResults\Extraction\LaboratoryUnitNormalizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\LaboratoryResults\Support\HemogramAnalyteCatalog;

class LaboratoryResultExtractionComparatorTest extends TestCase
{
    use HemogramAnalyteCatalog;

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

        $this->seedHemogramAnalyteCatalog();

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
    public function clasifica_match_cuando_text_y_vision_coinciden(): void
    {
        $text = [new LaboratoryResultObservationCandidate(
            analyteNameRaw: 'RDW',
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 13.1,
            unit: '%',
            referenceLow: 12.0,
            referenceHigh: 17.7,
            referenceText: '12.0-17.7',
            confidence: 0.9,
        )];

        $vision = [new LaboratoryResultObservationCandidate(
            analyteNameRaw: 'RDW',
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 13.1,
            unit: '%',
            referenceLow: 12.0,
            referenceHigh: 17.7,
            referenceText: '12.0-17.7',
            confidence: 0.98,
        )];

        $summary = $this->comparator->compare($text, $vision);

        $this->assertSame(1, $summary->matchCount);
        $this->assertSame('FAMEDIC_CBC_RDW', $summary->items[0]->analyteCode);
        $this->assertSame(LaboratoryObservationSourcePriority::VisionConfirmation, $summary->items[0]->sourcePriority);
    }

    #[Test]
    public function hgm_pg_vs_pg_es_match(): void
    {
        $text = [new LaboratoryResultObservationCandidate(
            analyteNameRaw: 'HGM',
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 29.0,
            unit: 'pg',
            referenceLow: 26.8,
            referenceHigh: 33.2,
            referenceText: '26.8-33.2',
            confidence: 0.9,
        )];

        $vision = [new LaboratoryResultObservationCandidate(
            analyteNameRaw: 'HGM',
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 29.0,
            unit: 'pg',
            referenceLow: 26.8,
            referenceHigh: 33.2,
            referenceText: '26.8-33.2',
            confidence: 0.98,
        )];

        $summary = $this->comparator->compare($text, $vision);

        $this->assertSame(1, $summary->matchCount);
        $this->assertSame('FAMEDIC_CBC_HGM', $summary->items[0]->analyteCode);
    }

    #[Test]
    public function hgm_pg_vs_g_dl_es_conflict(): void
    {
        $text = [new LaboratoryResultObservationCandidate(
            analyteNameRaw: 'HGM',
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 29.0,
            unit: 'pg',
            referenceText: '26.8-33.2',
            confidence: 0.9,
        )];

        $vision = [new LaboratoryResultObservationCandidate(
            analyteNameRaw: 'HGM',
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 29.0,
            unit: 'g/dL',
            referenceText: '26.8-33.2',
            confidence: 0.98,
        )];

        $summary = $this->comparator->compare($text, $vision);

        $this->assertSame(1, $summary->conflictCount);
        $this->assertContains('unit', $summary->items[0]->conflictFields);
        $this->assertSame(LaboratoryObservationSourcePriority::ConflictRequiresReview, $summary->items[0]->sourcePriority);
        $this->assertTrue($summary->items[0]->requiresReview);
    }

    #[Test]
    public function eritrocitos_mill_ul_vs_10_6_ul_es_match(): void
    {
        $text = [new LaboratoryResultObservationCandidate(
            analyteNameRaw: 'Eritrocitos',
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 4.86,
            unit: 'mill/µL',
            referenceText: '3.87-5.44',
            confidence: 0.9,
        )];

        $vision = [new LaboratoryResultObservationCandidate(
            analyteNameRaw: 'Eritrocitos',
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 4.86,
            unit: '10^6/uL',
            referenceText: '3.87-5.44',
            confidence: 0.98,
        )];

        $summary = $this->comparator->compare($text, $vision);

        $this->assertSame(1, $summary->matchCount);
        $this->assertSame('1e6_per_ul', $summary->items[0]->unitEquivalenceKeyText);
        $this->assertSame('1e6_per_ul', $summary->items[0]->unitEquivalenceKeyVision);
    }

    #[Test]
    public function clasifica_conflict_cuando_el_valor_numerico_difiere(): void
    {
        $text = [new LaboratoryResultObservationCandidate(
            analyteNameRaw: 'RDW',
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 13.1,
            unit: '%',
            referenceText: '12.0-17.7',
            confidence: 0.9,
        )];

        $vision = [new LaboratoryResultObservationCandidate(
            analyteNameRaw: 'RDW',
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 12.1,
            unit: '%',
            referenceText: '12.0-17.7',
            confidence: 0.98,
        )];

        $summary = $this->comparator->compare($text, $vision);

        $this->assertSame(1, $summary->conflictCount);
        $this->assertContains('numeric_value', $summary->items[0]->conflictFields);
    }

    #[Test]
    public function referencia_diferente_genera_conflict(): void
    {
        $text = [new LaboratoryResultObservationCandidate(
            analyteNameRaw: 'Eritrocitos',
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 4.86,
            unit: 'mill/µL',
            referenceLow: 3.87,
            referenceHigh: 5.44,
            referenceText: '3.87-5.44',
            confidence: 0.9,
        )];

        $vision = [new LaboratoryResultObservationCandidate(
            analyteNameRaw: 'Eritrocitos',
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 4.86,
            unit: '10^6/uL',
            referenceLow: 4.87,
            referenceHigh: 5.4,
            referenceText: '4.87-5.4',
            confidence: 0.98,
        )];

        $summary = $this->comparator->compare($text, $vision);

        $this->assertSame(1, $summary->conflictCount);
        $this->assertSame(LaboratoryReferenceComparisonOutcome::Conflict, $summary->items[0]->referenceComparison);
    }

    #[Test]
    public function clasifica_vision_only_y_text_only(): void
    {
        $text = [new LaboratoryResultObservationCandidate(
            analyteNameRaw: 'VPM',
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 10.8,
            unit: 'fL',
            confidence: 0.9,
        )];

        $vision = [
            new LaboratoryResultObservationCandidate(
                analyteNameRaw: 'VPM',
                valueType: LaboratoryResultObservationValueType::Numeric,
                numericValue: 10.8,
                unit: 'fL',
                confidence: 0.98,
            ),
            new LaboratoryResultObservationCandidate(
                analyteNameRaw: 'Hemoglobina',
                valueType: LaboratoryResultObservationValueType::Numeric,
                numericValue: 14.2,
                unit: 'g/dL',
                confidence: 0.95,
            ),
        ];

        $summary = $this->comparator->compare($text, $vision);

        $this->assertSame(1, $summary->matchCount);
        $this->assertSame(1, $summary->visionOnlyCount);
        $this->assertTrue($summary->items[0]->requiresReview === false || $summary->items[0]->sourcePriority === LaboratoryObservationSourcePriority::VisionConfirmation);
    }

    #[Test]
    public function plaquetas_365_vs_96_es_conflict(): void
    {
        $text = [new LaboratoryResultObservationCandidate(
            analyteNameRaw: 'PLAQUETAS',
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 365.0,
            unit: 'miles/µL',
            referenceLow: 167.0,
            referenceHigh: 431.0,
            referenceText: '167-431',
            confidence: 0.9,
        )];

        $vision = [new LaboratoryResultObservationCandidate(
            analyteNameRaw: 'PLAQUETAS',
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 96.0,
            unit: 'miles/uL',
            referenceText: '167-431',
            confidence: 1.0,
        )];

        $summary = $this->comparator->compare($text, $vision);

        $this->assertSame(1, $summary->conflictCount);
        $this->assertSame('FAMEDIC_CBC_PLT', $summary->items[0]->analyteCode);
        $this->assertContains('numeric_value', $summary->items[0]->conflictFields);
        $this->assertSame(LaboratoryResultExtractionComparisonOutcome::Conflict, $summary->items[0]->outcome);
    }

    #[Test]
    public function neutrofilos_totales_duplicado_vision_prefiere_unidad_catalogo(): void
    {
        $text = [new LaboratoryResultObservationCandidate(
            analyteNameRaw: 'NEUTROFILOS TOTALES',
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 66.7,
            unit: '%',
            referenceLow: 39.6,
            referenceHigh: 76.1,
            referenceText: '39.6-76.1',
            confidence: 0.92,
        )];

        $vision = [
            new LaboratoryResultObservationCandidate(
                analyteNameRaw: 'NEUTROFILOS TOTALES',
                valueType: LaboratoryResultObservationValueType::Numeric,
                numericValue: 8.8,
                unit: 'miles/uL',
                referenceLow: 1.7,
                referenceHigh: 6.5,
                referenceText: '1.7 - 6.5',
                confidence: 1.0,
            ),
            new LaboratoryResultObservationCandidate(
                analyteNameRaw: 'NEUTROFILOS TOTALES',
                valueType: LaboratoryResultObservationValueType::Numeric,
                numericValue: 66.7,
                unit: '%',
                referenceLow: 39.6,
                referenceHigh: 76.1,
                referenceText: '39.6 - 76.1',
                confidence: 1.0,
            ),
        ];

        $summary = $this->comparator->compare($text, $vision);

        $this->assertSame(1, $summary->matchCount);
        $this->assertSame(0, $summary->conflictCount);
        $this->assertSame('FAMEDIC_CBC_NEUT', $summary->items[0]->analyteKey);
        $this->assertSame(66.7, $summary->items[0]->visionCandidate?->numericValue);
        $this->assertSame('%', $summary->items[0]->visionCandidate?->unit);
    }

    #[Test]
    public function trigliceridos_deseable_vision_vs_texto_normalizado_es_match(): void
    {
        $text = [new LaboratoryResultObservationCandidate(
            analyteNameRaw: 'TRIGLICERIDOS',
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 75.0,
            unit: 'mg/dL',
            referenceHigh: 150.0,
            referenceText: '<150',
            confidence: 0.84,
            parseRule: 'numeric_gda_categorical_reference_v1',
        )];

        $vision = [new LaboratoryResultObservationCandidate(
            analyteNameRaw: 'TRIGLICERIDOS',
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 75.0,
            unit: 'mg/dL',
            referenceHigh: 150.0,
            referenceText: '<150',
            confidence: 0.95,
            parseRule: 'vision_v3',
        )];

        $summary = $this->comparator->compare($text, $vision);

        $this->assertSame(1, $summary->matchCount);
        $this->assertSame(0, $summary->conflictCount);
        $this->assertSame(LaboratoryReferenceComparisonOutcome::Match, $summary->items[0]->referenceComparison);
    }

    #[Test]
    public function clasifica_text_only(): void
    {
        $text = [new LaboratoryResultObservationCandidate(
            analyteNameRaw: 'Neutrófilos totales',
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: 47.0,
            unit: '%',
            confidence: 0.9,
        )];

        $summary = $this->comparator->compare($text, []);

        $this->assertSame(1, $summary->textOnlyCount);
        $this->assertSame(LaboratoryResultExtractionComparisonOutcome::TextOnly, $summary->items[0]->outcome);
        $this->assertSame(LaboratoryObservationSourcePriority::TextPrimary, $summary->items[0]->sourcePriority);
    }
}
