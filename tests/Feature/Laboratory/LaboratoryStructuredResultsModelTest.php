<?php

namespace Tests\Feature\Laboratory;

use App\Enums\Gender;
use App\Enums\LaboratoryAnalyteAliasSource;
use App\Enums\LaboratoryAnalyteValueKind;
use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryResultAbnormalSource;
use App\Enums\LaboratoryResultEventType;
use App\Enums\LaboratoryResultExtractionMethod;
use App\Enums\LaboratoryResultExtractionStatus;
use App\Enums\LaboratoryResultObservationValueType;
use App\Enums\LaboratoryResultReferenceStatus;
use App\Enums\LaboratoryResultReportSource;
use App\Enums\LaboratoryResultStructuredStatus;
use App\Models\LaboratoryAnalyte;
use App\Models\LaboratoryAnalyteAlias;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryResultObservation;
use App\Models\LaboratoryResultReport;
use App\Models\LaboratoryResultStatus;
use App\Models\Customer;
use App\Models\LaboratoryResultVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryStructuredResultsModelTest extends TestCase
{
    use GdaResultsStorageIsolatedSchema;
    use StructuredResultsIsolatedSchema;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        $this->bootstrapIsolatedSchema();
        $this->bootstrapStructuredResultsSchema();
    }

    protected function tearDown(): void
    {
        $this->tearDownStructuredResultsSchema();
        $this->tearDownIsolatedSchema();

        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function laboratory_analyte_se_puede_crear(): void
    {
        $analyte = LaboratoryAnalyte::query()->create([
            'code' => 'glucose_serum',
            'canonical_name' => 'Glucosa en suero',
            'default_unit' => 'mg/dL',
            'value_kind' => LaboratoryAnalyteValueKind::Numeric,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('laboratory_analytes', [
            'id' => $analyte->id,
            'code' => 'glucose_serum',
        ]);
    }

    #[Test]
    public function alias_pertenece_a_analyte(): void
    {
        $analyte = LaboratoryAnalyte::factory()->create();

        $alias = LaboratoryAnalyteAlias::query()->create([
            'laboratory_analyte_id' => $analyte->id,
            'alias_normalized' => 'glucosa',
            'alias_raw' => 'Glucosa',
            'source' => LaboratoryAnalyteAliasSource::Manual,
        ]);

        $this->assertTrue($alias->analyte->is($analyte));
        $this->assertTrue($analyte->fresh()->aliases->contains($alias));
    }

    #[Test]
    public function report_pertenece_a_purchase_y_result_version(): void
    {
        [$purchase, $version] = $this->seedPurchaseWithVersion();

        $report = LaboratoryResultReport::factory()->forVersion($version)->create([
            'input_hash' => hash('sha256', 'attempt-1'),
        ]);

        $this->assertTrue($report->purchase->is($purchase));
        $this->assertTrue($report->resultVersion->is($version));
        $this->assertTrue($purchase->resultReports->contains($report));
        $this->assertTrue($version->resultReports->contains($report));
    }

    #[Test]
    public function report_tiene_multiples_observations(): void
    {
        [$purchase, $version] = $this->seedPurchaseWithVersion();

        $report = LaboratoryResultReport::factory()->forVersion($version)->create([
            'input_hash' => hash('sha256', 'attempt-obs'),
        ]);

        LaboratoryResultObservation::factory()->count(3)->create([
            'laboratory_result_report_id' => $report->id,
        ]);

        $this->assertCount(3, $report->fresh()->observations);
    }

    #[Test]
    public function observation_puede_pertenecer_a_analyte_o_existir_sin_analyte(): void
    {
        [$purchase, $version] = $this->seedPurchaseWithVersion();
        $analyte = LaboratoryAnalyte::factory()->create();

        $report = LaboratoryResultReport::factory()->forVersion($version)->create([
            'input_hash' => hash('sha256', 'attempt-analyte'),
        ]);

        $linked = LaboratoryResultObservation::factory()->create([
            'laboratory_result_report_id' => $report->id,
            'laboratory_analyte_id' => $analyte->id,
            'analyte_code' => $analyte->code,
        ]);

        $unlinked = LaboratoryResultObservation::factory()->create([
            'laboratory_result_report_id' => $report->id,
            'laboratory_analyte_id' => null,
            'analyte_code' => null,
        ]);

        $this->assertTrue($linked->analyte->is($analyte));
        $this->assertNull($unlinked->analyte);
    }

    #[Test]
    public function observation_puede_tener_purchase_item_nullable(): void
    {
        [$purchase, $version, $item] = $this->seedPurchaseWithVersion(returnItem: true);

        $report = LaboratoryResultReport::factory()->forVersion($version)->create([
            'input_hash' => hash('sha256', 'attempt-item'),
        ]);

        $withItem = LaboratoryResultObservation::factory()->create([
            'laboratory_result_report_id' => $report->id,
            'laboratory_purchase_item_id' => $item->id,
        ]);

        $withoutItem = LaboratoryResultObservation::factory()->create([
            'laboratory_result_report_id' => $report->id,
            'laboratory_purchase_item_id' => null,
        ]);

        $this->assertTrue($withItem->purchaseItem->is($item));
        $this->assertNull($withoutItem->purchase_item_id);
    }

    #[Test]
    public function json_fields_tienen_casts_correctos(): void
    {
        [$purchase, $version] = $this->seedPurchaseWithVersion();

        $report = LaboratoryResultReport::factory()->forVersion($version)->create([
            'input_hash' => hash('sha256', 'attempt-json-report'),
            'validation_errors' => ['field' => 'invalid'],
            'raw_extraction_payload' => ['rows' => [['name' => 'Glucosa']]],
        ]);

        $observation = LaboratoryResultObservation::factory()->create([
            'laboratory_result_report_id' => $report->id,
            'metadata' => ['panel' => 'Quimica'],
            'source_bbox' => ['x' => 1, 'y' => 2, 'w' => 10, 'h' => 5],
        ]);

        $report = $report->fresh();
        $observation = $observation->fresh();

        $this->assertIsArray($report->validation_errors);
        $this->assertSame('invalid', $report->validation_errors['field']);
        $this->assertIsArray($report->raw_extraction_payload);
        $this->assertIsArray($observation->metadata);
        $this->assertIsArray($observation->source_bbox);
    }

    #[Test]
    public function enums_solo_aceptan_valores_validos_en_modelo(): void
    {
        LaboratoryAnalyte::factory()->create([
            'value_kind' => LaboratoryAnalyteValueKind::Either,
        ]);

        [$purchase, $version] = $this->seedPurchaseWithVersion();

        $report = LaboratoryResultReport::factory()->forVersion($version)->create([
            'input_hash' => hash('sha256', 'attempt-enums'),
            'source' => LaboratoryResultReportSource::ManualAdmin,
            'extraction_method' => LaboratoryResultExtractionMethod::Hybrid,
            'extraction_status' => LaboratoryResultExtractionStatus::Processing,
            'structured_status' => LaboratoryResultStructuredStatus::Draft,
        ]);

        $observation = LaboratoryResultObservation::factory()->create([
            'laboratory_result_report_id' => $report->id,
            'value_type' => LaboratoryResultObservationValueType::Qualitative,
            'reference_status' => LaboratoryResultReferenceStatus::NotApplicable,
            'abnormal_source' => LaboratoryResultAbnormalSource::None,
            'extraction_method' => LaboratoryResultExtractionMethod::PdfText,
        ]);

        $this->assertSame(LaboratoryResultReportSource::ManualAdmin, $report->source);
        $this->assertSame(LaboratoryResultReferenceStatus::NotApplicable, $observation->reference_status);
    }

    #[Test]
    public function input_hash_permite_distinguir_dos_extracciones_del_mismo_pdf(): void
    {
        [$purchase, $version] = $this->seedPurchaseWithVersion();

        $baseHash = hash('sha256', $version->sha256.'|extractor_v1|');

        LaboratoryResultReport::factory()->forVersion($version)->create([
            'input_hash' => hash('sha256', $baseHash.'prompt_v1'),
            'extractor_version' => 'extractor_v1',
            'prompt_version' => 1,
        ]);

        $second = LaboratoryResultReport::factory()->forVersion($version)->create([
            'input_hash' => hash('sha256', $baseHash.'prompt_v2'),
            'extractor_version' => 'extractor_v1',
            'prompt_version' => 2,
        ]);

        $this->assertSame(2, LaboratoryResultReport::query()
            ->where('laboratory_result_version_id', $version->id)
            ->count());
        $this->assertNotSame($second->input_hash, LaboratoryResultReport::query()->first()->input_hash);
    }

    #[Test]
    public function input_hash_duplicado_misma_version_falla_por_unique(): void
    {
        [$purchase, $version] = $this->seedPurchaseWithVersion();
        $inputHash = hash('sha256', 'duplicate');

        LaboratoryResultReport::factory()->forVersion($version)->create([
            'input_hash' => $inputHash,
        ]);

        $this->expectException(QueryException::class);

        LaboratoryResultReport::factory()->forVersion($version)->create([
            'input_hash' => $inputHash,
        ]);
    }

    #[Test]
    public function una_version_soporta_multiples_reports(): void
    {
        [$purchase, $version] = $this->seedPurchaseWithVersion();

        LaboratoryResultReport::factory()->forVersion($version)->count(3)->sequence(
            ['input_hash' => hash('sha256', 'a')],
            ['input_hash' => hash('sha256', 'b')],
            ['input_hash' => hash('sha256', 'c')],
        )->create();

        $this->assertSame(3, $version->resultReports()->count());
    }

    #[Test]
    public function solo_un_report_publicado_activo_por_version_en_base_de_datos(): void
    {
        [$purchase, $version] = $this->seedPurchaseWithVersion();

        LaboratoryResultReport::factory()->published($version)->create([
            'input_hash' => hash('sha256', 'published-1'),
        ]);

        $this->expectException(QueryException::class);

        LaboratoryResultReport::factory()->published($version)->create([
            'input_hash' => hash('sha256', 'published-2'),
        ]);
    }

    #[Test]
    public function report_superseded_libera_el_slot_publicado(): void
    {
        [$purchase, $version] = $this->seedPurchaseWithVersion();

        $first = LaboratoryResultReport::factory()->published($version)->create([
            'input_hash' => hash('sha256', 'published-first'),
        ]);

        $first->update([
            'structured_status' => LaboratoryResultStructuredStatus::Superseded,
            'superseded_at' => now(),
            'published_version_slot' => null,
        ]);

        $second = LaboratoryResultReport::factory()->published($version)->create([
            'input_hash' => hash('sha256', 'published-second'),
        ]);

        $this->assertSame(1, LaboratoryResultReport::query()->activePublished()->count());
        $this->assertTrue(LaboratoryResultReport::query()->activePublished()->first()->is($second));
    }

    #[Test]
    public function nuevos_event_types_de_extraccion_estan_disponibles(): void
    {
        $this->assertSame('EXTRACTION_REQUESTED', LaboratoryResultEventType::ExtractionRequested->value);
        $this->assertSame('STRUCTURE_PUBLISHED', LaboratoryResultEventType::StructurePublished->value);
        $this->assertSame('MANUAL_CORRECTION_APPLIED', LaboratoryResultEventType::ManualCorrectionApplied->value);
    }

    /**
     * @return array{0: LaboratoryPurchase, 1: LaboratoryResultVersion}|array{0: LaboratoryPurchase, 1: LaboratoryResultVersion, 2: LaboratoryPurchaseItem}
     */
    private function seedPurchaseWithVersion(bool $returnItem = false): array
    {
        $user = User::query()->create([
            'name' => 'Paciente Test',
            'email' => 'patient-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);

        $customer = Customer::query()->create([
            'user_id' => $user->id,
        ]);

        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $customer->id,
            'brand' => LaboratoryBrand::OLAB,
            'gda_order_id' => 'ORD-'.fake()->unique()->numerify('#####'),
            'name' => 'Test',
            'paternal_lastname' => 'Patient',
            'maternal_lastname' => 'Structured',
            'phone' => '8112345678',
            'phone_country' => 'MX',
            'birth_date' => '1990-01-01',
            'gender' => Gender::MALE,
            'street' => 'Calle',
            'number' => '1',
            'neighborhood' => 'Centro',
            'state' => 'NL',
            'city' => 'Monterrey',
            'zipcode' => '64000',
            'total_cents' => 10000,
        ]);

        $item = LaboratoryPurchaseItem::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'gda_id' => 'GDA-1',
            'name' => 'Quimica sanguinea',
            'price_cents' => 10000,
        ]);

        $status = LaboratoryResultStatus::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'laboratory_purchase_item_id' => $item->id,
            'status' => 'complete',
            'first_available_at' => now(),
        ]);

        $version = LaboratoryResultVersion::query()->create([
            'laboratory_result_status_id' => $status->id,
            'storage_path' => 'results/gda-'.$purchase->id.'-abc123.pdf',
            'sha256' => hash('sha256', 'pdf-binary-fixture'),
            'source' => 'gda',
            'classification' => 'complete',
            'classification_reason' => 'explicit_complete_signal',
            'matched_rule' => 'test',
            'classifier' => 'deterministic_pdf_v1',
            'classified_at' => now(),
        ]);

        if ($returnItem) {
            return [$purchase, $version, $item];
        }

        return [$purchase, $version];
    }
}
