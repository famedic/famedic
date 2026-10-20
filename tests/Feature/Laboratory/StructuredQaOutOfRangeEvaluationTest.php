<?php

namespace Tests\Feature\Laboratory;

use App\Enums\Gender;
use App\Enums\LaboratoryAnalyteValueKind;
use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryResultAbnormalSource;
use App\Enums\LaboratoryResultExtractionMethod;
use App\Enums\LaboratoryResultObservationValueType;
use App\Enums\LaboratoryResultReferenceStatus;
use App\Enums\LaboratoryResultStructuredStatus;
use App\Models\Customer;
use App\Models\LaboratoryAnalyte;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryResultObservation;
use App\Models\LaboratoryResultReport;
use App\Models\LaboratoryResultStatus;
use App\Models\LaboratoryResultVersion;
use App\Models\User;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultInputHash;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultReferenceEvaluation;
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredQaOutOfRangeService;
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredQaRunOptions;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StructuredQaOutOfRangeEvaluationTest extends TestCase
{
    use GdaResultsStorageIsolatedSchema;
    use StructuredResultsIsolatedSchema;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();

        $this->bootstrapIsolatedSchema();
        $this->bootstrapStructuredResultsSchema();

        Config::set('laboratory-results.structured_shadow_qa.enabled', true);
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
    public function evalua_observaciones_shadow_y_persiste_reference_status(): void
    {
        [$version, $report] = $this->seedShadowReportWithObservations();

        $result = $this->service()->evaluate(new LaboratoryStructuredQaRunOptions(
            dryRun: false,
            evaluateOutOfRange: true,
        ));

        $this->assertSame(3, $result->observationsInput);
        $this->assertSame(3, $result->evaluatedCount);
        $this->assertSame(3, $result->updatedCount);

        $crea = LaboratoryResultObservation::query()->where('analyte_code', 'FAMEDIC_CHEM_CREA')->first();
        $chol = LaboratoryResultObservation::query()->where('analyte_code', 'FAMEDIC_CHEM_CHOL')->first();
        $hgb = LaboratoryResultObservation::query()->where('analyte_code', 'FAMEDIC_CBC_HGB')->first();

        $this->assertSame(LaboratoryResultReferenceStatus::Low, $crea->reference_status);
        $this->assertSame(LaboratoryResultReferenceStatus::High, $chol->reference_status);
        $this->assertSame(LaboratoryResultReferenceStatus::Normal, $hgb->reference_status);
        $this->assertTrue($chol->abnormal_flag);
        $this->assertSame(LaboratoryResultAbnormalSource::Computed, $chol->abnormal_source);
        $this->assertSame(LaboratoryResultReferenceEvaluation::METHOD, $chol->metadata['reference_evaluation']['method']);
    }

    #[Test]
    public function evaluacion_es_idempotente(): void
    {
        $this->seedShadowReportWithObservations();
        $service = $this->service();

        $first = $service->evaluate(new LaboratoryStructuredQaRunOptions(
            dryRun: false,
            evaluateOutOfRange: true,
        ));
        $second = $service->evaluate(new LaboratoryStructuredQaRunOptions(
            dryRun: false,
            evaluateOutOfRange: true,
        ));

        $this->assertSame(3, $first->updatedCount);
        $this->assertSame(0, $second->updatedCount);
        $this->assertSame(3, $second->duplicatePrevented);
    }

    #[Test]
    public function dry_run_no_modifica_observaciones(): void
    {
        $this->seedShadowReportWithObservations();

        $this->service()->evaluate(new LaboratoryStructuredQaRunOptions(
            dryRun: true,
            evaluateOutOfRange: true,
        ));

        $observation = LaboratoryResultObservation::query()->first();
        $this->assertSame(LaboratoryResultReferenceStatus::Unknown, $observation->reference_status);
        $this->assertNull($observation->metadata['reference_evaluation'] ?? null);
    }

    #[Test]
    public function report_permanece_shadow_y_no_publicado(): void
    {
        [$version, $report] = $this->seedShadowReportWithObservations();

        $this->service()->evaluate(new LaboratoryStructuredQaRunOptions(
            dryRun: false,
            evaluateOutOfRange: true,
        ));

        $report->refresh();
        $this->assertTrue($report->isShadowQa());
        $this->assertNull($report->published_version_slot);
        $this->assertSame(LaboratoryResultStructuredStatus::Draft, $report->structured_status);
        $this->assertSame(0, LaboratoryResultReport::query()->activePublished()->count());
    }

    #[Test]
    public function patient_facing_no_incluye_shadow_tras_evaluacion(): void
    {
        [$version] = $this->seedShadowReportWithObservations();

        LaboratoryResultReport::factory()->published($version)->create([
            'laboratory_purchase_id' => $version->resultStatus->laboratory_purchase_id,
            'input_hash' => hash('sha256', 'published-text'),
            'extraction_method' => LaboratoryResultExtractionMethod::PdfText,
        ]);

        $this->service()->evaluate(new LaboratoryStructuredQaRunOptions(
            dryRun: false,
            evaluateOutOfRange: true,
        ));

        $this->assertSame(1, LaboratoryResultReport::query()->activePublished()->count());
        $this->assertSame(1, LaboratoryResultReport::query()->shadowQa()->count());
    }

    /**
     * @return array{0: LaboratoryResultVersion, 1: LaboratoryResultReport}
     */
    private function seedShadowReportWithObservations(): array
    {
        $analytes = [
            ['code' => 'FAMEDIC_CBC_HGB', 'unit' => 'g/dL'],
            ['code' => 'FAMEDIC_CHEM_CREA', 'unit' => 'mg/dL'],
            ['code' => 'FAMEDIC_CHEM_CHOL', 'unit' => 'mg/dL'],
        ];

        foreach ($analytes as $entry) {
            LaboratoryAnalyte::query()->create([
                'code' => $entry['code'],
                'canonical_name' => $entry['code'],
                'default_unit' => $entry['unit'],
                'value_kind' => LaboratoryAnalyteValueKind::Numeric,
                'is_active' => true,
            ]);
        }

        $user = User::query()->create([
            'name' => 'QA OOR',
            'email' => 'oor-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);
        $customer = Customer::query()->create(['user_id' => $user->id]);

        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $customer->id,
            'brand' => LaboratoryBrand::OLAB,
            'gda_order_id' => 'ORD-OOR-'.uniqid(),
            'name' => 'Test',
            'paternal_lastname' => 'Patient',
            'maternal_lastname' => 'OOR',
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
            'gda_id' => 'GDA-OOR',
            'name' => 'Panel',
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
            'storage_path' => 'results/gda-oor-test.pdf',
            'sha256' => hash('sha256', 'oor-version'),
            'source' => 'gda',
            'classification' => 'complete',
            'classification_reason' => 'test',
            'matched_rule' => 'test',
            'classifier' => 'deterministic_pdf_v1',
            'classified_at' => now(),
        ]);

        $report = LaboratoryResultReport::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'laboratory_result_version_id' => $version->id,
            'source' => \App\Enums\LaboratoryResultReportSource::Gda,
            'extraction_method' => LaboratoryResultExtractionMethod::Vision,
            'extraction_status' => \App\Enums\LaboratoryResultExtractionStatus::Extracted,
            'structured_status' => LaboratoryResultStructuredStatus::Draft,
            'observation_count' => 3,
            'input_hash' => LaboratoryResultInputHash::computeExperiment(
                $version->sha256,
                LaboratoryResultInputHash::STRUCTURED_SHADOW_QA_EXPERIMENT_KEY,
                LaboratoryResultInputHash::STRUCTURED_SHADOW_QA_EXTRACTOR_VERSION,
            ),
            'extractor_version' => LaboratoryResultInputHash::STRUCTURED_SHADOW_QA_EXTRACTOR_VERSION,
            'published_version_slot' => null,
            'raw_extraction_payload' => [
                'shadow_qa' => true,
                'mode' => 'shadow_qa',
                'source' => 'vision',
            ],
        ]);

        $rows = [
            ['FAMEDIC_CBC_HGB', 14.4, '11.7 - 16.3', 11.7, 16.3],
            ['FAMEDIC_CHEM_CREA', 0.5, '0.55 - 1.02', 0.55, 1.02],
            ['FAMEDIC_CHEM_CHOL', 247, '<200', null, 200],
        ];

        foreach ($rows as [$code, $value, $refText, $low, $high]) {
            $analyte = LaboratoryAnalyte::query()->where('code', $code)->first();

            LaboratoryResultObservation::query()->create([
                'laboratory_result_report_id' => $report->id,
                'laboratory_analyte_id' => $analyte->id,
                'analyte_code' => $code,
                'analyte_name_raw' => $code,
                'numeric_value' => $value,
                'value_type' => LaboratoryResultObservationValueType::Numeric,
                'unit' => $analyte->default_unit,
                'unit_raw' => $analyte->default_unit,
                'reference_text' => $refText,
                'reference_low' => $low,
                'reference_high' => $high,
                'reference_status' => LaboratoryResultReferenceStatus::Unknown,
                'abnormal_flag' => null,
                'abnormal_source' => LaboratoryResultAbnormalSource::None,
                'extraction_method' => LaboratoryResultExtractionMethod::Vision,
                'source_page' => 1,
                'metadata' => ['shadow_qa' => true],
            ]);
        }

        return [$version, $report];
    }

    private function service(): LaboratoryStructuredQaOutOfRangeService
    {
        return app(LaboratoryStructuredQaOutOfRangeService::class);
    }
}
