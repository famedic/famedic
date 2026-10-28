<?php

namespace Tests\Unit\LaboratoryResults\StructuredQa;

use App\Enums\Gender;
use App\Enums\LaboratoryAnalyteValueKind;
use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryResultExtractionMethod;
use App\Enums\LaboratoryResultObservationValueType;
use App\Enums\LaboratoryResultReferenceStatus;
use App\Enums\LaboratoryResultStructuredStatus;
use App\Enums\LaboratoryStructuredResultPromotionStatus;
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
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredResultPromotionGate;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Laboratory\GdaResultsStorageIsolatedSchema;
use Tests\Feature\Laboratory\StructuredResultsIsolatedSchema;
use Tests\TestCase;

class LaboratoryStructuredResultPromotionGateTest extends TestCase
{
    use GdaResultsStorageIsolatedSchema;
    use StructuredResultsIsolatedSchema;

    private LaboratoryStructuredResultPromotionGate $gate;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();
        $this->bootstrapIsolatedSchema();
        $this->bootstrapStructuredResultsSchema();
        $this->gate = app(LaboratoryStructuredResultPromotionGate::class);
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
    public function candidato_valido_es_validated(): void
    {
        $observation = $this->seedShadowObservation([
            'confidence' => 0.95,
            'reference_status' => LaboratoryResultReferenceStatus::Normal,
            'metadata' => [
                'shadow_qa' => true,
                'purchase_association_method' => 'result_status_confirmed',
                'identity_evidence' => 'test',
                'reference_evaluation' => ['evaluated' => true],
            ],
        ]);

        $result = $this->gate->evaluate($observation);

        $this->assertSame(LaboratoryStructuredResultPromotionStatus::Validated, $result->status);
        $this->assertSame([], $result->reasonCodes);
    }

    #[Test]
    public function analyte_invalido_es_rejected(): void
    {
        $observation = $this->seedShadowObservation([
            'laboratory_analyte_id' => null,
            'analyte_code' => 'INVALID',
        ]);

        $result = $this->gate->evaluate($observation);

        $this->assertSame(LaboratoryStructuredResultPromotionStatus::Rejected, $result->status);
        $this->assertContains('analyte_not_identified', $result->reasonCodes);
    }

    #[Test]
    public function valor_numerico_ausente_es_rejected(): void
    {
        $observation = $this->seedShadowObservation([
            'numeric_value' => null,
        ]);

        $result = $this->gate->evaluate($observation);

        $this->assertSame(LaboratoryStructuredResultPromotionStatus::Rejected, $result->status);
        $this->assertContains('numeric_value_missing', $result->reasonCodes);
    }

    #[Test]
    public function unidad_incompatible_es_rejected(): void
    {
        $observation = $this->seedShadowObservation([
            'unit' => 'pg',
            'unit_raw' => 'pg',
        ]);

        $result = $this->gate->evaluate($observation);

        $this->assertSame(LaboratoryStructuredResultPromotionStatus::Rejected, $result->status);
        $this->assertContains('unit_incompatible', $result->reasonCodes);
    }

    #[Test]
    public function referencia_ausente_es_needs_review(): void
    {
        $observation = $this->seedShadowObservation([
            'reference_text' => null,
            'reference_low' => null,
            'reference_high' => null,
            'reference_status' => LaboratoryResultReferenceStatus::Unknown,
            'metadata' => [
                'shadow_qa' => true,
                'purchase_association_method' => 'result_status_confirmed',
                'identity_evidence' => 'test',
            ],
        ]);

        $result = $this->gate->evaluate($observation);

        $this->assertSame(LaboratoryStructuredResultPromotionStatus::NeedsReview, $result->status);
        $this->assertContains('reference_not_fully_evaluable', $result->reasonCodes);
    }

    #[Test]
    public function confidence_baja_es_needs_review(): void
    {
        $observation = $this->seedShadowObservation([
            'confidence' => 0.5,
            'reference_status' => LaboratoryResultReferenceStatus::High,
            'metadata' => [
                'shadow_qa' => true,
                'purchase_association_method' => 'result_status_confirmed',
                'identity_evidence' => 'test',
                'reference_evaluation' => ['evaluated' => true],
            ],
        ]);

        $result = $this->gate->evaluate($observation);

        $this->assertSame(LaboratoryStructuredResultPromotionStatus::NeedsReview, $result->status);
        $this->assertContains('confidence_below_threshold', $result->reasonCodes);
    }

    #[Test]
    public function purchase_association_unresolved_es_needs_review(): void
    {
        $observation = $this->seedShadowObservation([
            'laboratory_purchase_item_id' => null,
            'reference_status' => LaboratoryResultReferenceStatus::Normal,
            'metadata' => [
                'shadow_qa' => true,
                'identity_evidence' => 'test',
                'reference_evaluation' => ['evaluated' => true],
            ],
        ]);

        $result = $this->gate->evaluate($observation);

        $this->assertSame(LaboratoryStructuredResultPromotionStatus::NeedsReview, $result->status);
        $this->assertContains('purchase_association_unresolved', $result->reasonCodes);
    }

    #[Test]
    public function cualitativo_valido_es_validated(): void
    {
        $observation = $this->seedShadowObservation([
            'value_type' => LaboratoryResultObservationValueType::Qualitative,
            'numeric_value' => null,
            'text_value' => 'NEGATIVO',
            'reference_text' => 'NEGATIVO',
        ]);

        $result = $this->gate->evaluate($observation);

        $this->assertSame(LaboratoryStructuredResultPromotionStatus::Validated, $result->status);
    }

    #[Test]
    public function out_of_range_high_no_impide_validated(): void
    {
        $observation = $this->seedShadowObservation([
            'numeric_value' => 247,
            'reference_text' => '<200',
            'reference_high' => 200,
            'reference_status' => LaboratoryResultReferenceStatus::High,
            'metadata' => [
                'shadow_qa' => true,
                'purchase_association_method' => 'result_status_confirmed',
                'identity_evidence' => 'test',
                'reference_evaluation' => ['evaluated' => true],
            ],
        ]);

        $result = $this->gate->evaluate($observation);

        $this->assertSame(LaboratoryStructuredResultPromotionStatus::Validated, $result->status);
    }

    #[Test]
    public function pii_unsafe_es_rejected(): void
    {
        $observation = $this->seedShadowObservation([
            'metadata' => [
                'shadow_qa' => true,
                'pii_unsafe' => true,
                'purchase_association_method' => 'result_status_confirmed',
                'identity_evidence' => 'test',
                'reference_evaluation' => ['evaluated' => true],
            ],
        ]);

        $result = $this->gate->evaluate($observation);

        $this->assertSame(LaboratoryStructuredResultPromotionStatus::Rejected, $result->status);
        $this->assertContains('pii_unsafe', $result->reasonCodes);
    }

    #[Test]
    public function out_of_range_low_no_impide_validated(): void
    {
        $observation = $this->seedShadowObservation([
            'numeric_value' => 10.0,
            'reference_text' => '11.7 - 16.3',
            'reference_low' => 11.7,
            'reference_high' => 16.3,
            'reference_status' => LaboratoryResultReferenceStatus::Low,
            'metadata' => [
                'shadow_qa' => true,
                'purchase_association_method' => 'result_status_confirmed',
                'identity_evidence' => 'test',
                'reference_evaluation' => ['evaluated' => true],
            ],
        ]);

        $result = $this->gate->evaluate($observation);

        $this->assertSame(LaboratoryStructuredResultPromotionStatus::Validated, $result->status);
    }

    #[Test]
    public function sin_conflicto_text_vision_permite_validated(): void
    {
        [$version, $shadowReport] = $this->seedVersionAndShadowReport();

        LaboratoryResultReport::factory()->published($version)->create([
            'laboratory_purchase_id' => $version->resultStatus->laboratory_purchase_id,
            'input_hash' => hash('sha256', 'text-published-match'),
            'extraction_method' => LaboratoryResultExtractionMethod::PdfText,
        ]);

        $textReport = LaboratoryResultReport::query()->activePublished()->first();
        $analyte = LaboratoryAnalyte::query()->first();

        LaboratoryResultObservation::query()->create([
            'laboratory_result_report_id' => $textReport->id,
            'laboratory_analyte_id' => $analyte->id,
            'analyte_code' => $analyte->code,
            'analyte_name_raw' => 'HGB',
            'numeric_value' => 14.4,
            'value_type' => LaboratoryResultObservationValueType::Numeric,
            'reference_status' => LaboratoryResultReferenceStatus::Normal,
            'extraction_method' => LaboratoryResultExtractionMethod::PdfText,
        ]);

        $shadowObservation = LaboratoryResultObservation::query()->create([
            'laboratory_result_report_id' => $shadowReport->id,
            'laboratory_analyte_id' => $analyte->id,
            'analyte_code' => $analyte->code,
            'analyte_name_raw' => 'HGB',
            'numeric_value' => 14.4,
            'value_type' => LaboratoryResultObservationValueType::Numeric,
            'unit' => 'g/dL',
            'unit_raw' => 'g/dL',
            'reference_text' => '11.7 - 16.3',
            'reference_low' => 11.7,
            'reference_high' => 16.3,
            'reference_status' => LaboratoryResultReferenceStatus::Normal,
            'confidence' => 0.99,
            'extraction_method' => LaboratoryResultExtractionMethod::Vision,
            'metadata' => [
                'shadow_qa' => true,
                'purchase_association_method' => 'result_status_confirmed',
                'identity_evidence' => 'test',
                'reference_evaluation' => ['evaluated' => true],
            ],
        ]);

        $result = $this->gate->evaluate($shadowObservation);

        $this->assertSame(LaboratoryStructuredResultPromotionStatus::Validated, $result->status);
        $this->assertNotContains('text_vision_conflict', $result->reasonCodes);
    }

    #[Test]
    public function no_shadow_qa_es_rejected(): void
    {
        [, $report] = $this->seedVersionAndShadowReport();
        $report->update([
            'raw_extraction_payload' => ['shadow_qa' => false],
        ]);

        $observation = $this->seedShadowObservation([], $report);

        $result = $this->gate->evaluate($observation);

        $this->assertSame(LaboratoryStructuredResultPromotionStatus::Rejected, $result->status);
        $this->assertContains('not_shadow_qa', $result->reasonCodes);
    }

    #[Test]
    public function conflicto_text_vision_es_rejected(): void
    {
        [$version, $shadowReport] = $this->seedVersionAndShadowReport();

        LaboratoryResultReport::factory()->published($version)->create([
            'laboratory_purchase_id' => $version->resultStatus->laboratory_purchase_id,
            'input_hash' => hash('sha256', 'text-published'),
            'extraction_method' => LaboratoryResultExtractionMethod::PdfText,
        ]);

        $textReport = LaboratoryResultReport::query()->activePublished()->first();
        $analyte = LaboratoryAnalyte::query()->first();

        LaboratoryResultObservation::query()->create([
            'laboratory_result_report_id' => $textReport->id,
            'laboratory_analyte_id' => $analyte->id,
            'analyte_code' => $analyte->code,
            'analyte_name_raw' => 'HGB',
            'numeric_value' => 12.0,
            'value_type' => LaboratoryResultObservationValueType::Numeric,
            'reference_status' => LaboratoryResultReferenceStatus::Normal,
            'extraction_method' => LaboratoryResultExtractionMethod::PdfText,
        ]);

        $shadowObservation = LaboratoryResultObservation::query()->create([
            'laboratory_result_report_id' => $shadowReport->id,
            'laboratory_analyte_id' => $analyte->id,
            'analyte_code' => $analyte->code,
            'analyte_name_raw' => 'HGB',
            'numeric_value' => 14.4,
            'value_type' => LaboratoryResultObservationValueType::Numeric,
            'unit' => 'g/dL',
            'unit_raw' => 'g/dL',
            'reference_text' => '11.7 - 16.3',
            'reference_low' => 11.7,
            'reference_high' => 16.3,
            'reference_status' => LaboratoryResultReferenceStatus::Normal,
            'confidence' => 0.99,
            'extraction_method' => LaboratoryResultExtractionMethod::Vision,
            'metadata' => ['shadow_qa' => true, 'identity_evidence' => 'test', 'reference_evaluation' => ['evaluated' => true]],
        ]);

        $result = $this->gate->evaluate($shadowObservation);

        $this->assertSame(LaboratoryStructuredResultPromotionStatus::Rejected, $result->status);
        $this->assertContains('text_vision_conflict', $result->reasonCodes);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedShadowObservation(
        array $overrides = [],
        ?LaboratoryResultReport $report = null,
    ): LaboratoryResultObservation {
        if ($report === null) {
            [, $report] = $this->seedVersionAndShadowReport();
        }

        $analyte = LaboratoryAnalyte::query()->first();
        $purchaseItemId = LaboratoryPurchaseItem::query()
            ->where('laboratory_purchase_id', $report->laboratory_purchase_id)
            ->value('id');

        return LaboratoryResultObservation::query()->create(array_merge([
            'laboratory_result_report_id' => $report->id,
            'laboratory_analyte_id' => $analyte->id,
            'analyte_code' => $analyte->code,
            'analyte_name_raw' => 'HEMOGLOBINA',
            'numeric_value' => 14.4,
            'value_type' => LaboratoryResultObservationValueType::Numeric,
            'unit' => 'g/dL',
            'unit_raw' => 'g/dL',
            'reference_text' => '11.7 - 16.3',
            'reference_low' => 11.7,
            'reference_high' => 16.3,
            'reference_status' => LaboratoryResultReferenceStatus::Unknown,
            'laboratory_purchase_item_id' => $purchaseItemId,
            'extraction_method' => LaboratoryResultExtractionMethod::Vision,
            'confidence' => 0.99,
            'source_page' => 1,
            'metadata' => ['shadow_qa' => true],
        ], $overrides));
    }

    /**
     * @return array{0: LaboratoryResultVersion, 1: LaboratoryResultReport}
     */
    private function seedVersionAndShadowReport(): array
    {
        if (LaboratoryAnalyte::query()->count() === 0) {
            LaboratoryAnalyte::query()->create([
                'code' => 'FAMEDIC_CBC_HGB',
                'canonical_name' => 'Hemoglobina',
                'default_unit' => 'g/dL',
                'value_kind' => LaboratoryAnalyteValueKind::Numeric,
                'is_active' => true,
            ]);
        }

        $user = User::query()->create([
            'name' => 'Gate Test',
            'email' => 'gate-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);
        $customer = Customer::query()->create(['user_id' => $user->id]);

        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $customer->id,
            'brand' => LaboratoryBrand::OLAB,
            'gda_order_id' => 'ORD-GATE',
            'name' => 'Test',
            'paternal_lastname' => 'Patient',
            'maternal_lastname' => 'Gate',
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
            'storage_path' => 'results/gda-gate-test.pdf',
            'sha256' => hash('sha256', 'gate-version'),
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
            'observation_count' => 1,
            'input_hash' => LaboratoryResultInputHash::computeExperiment(
                $version->sha256,
                LaboratoryResultInputHash::STRUCTURED_SHADOW_QA_EXPERIMENT_KEY,
                LaboratoryResultInputHash::STRUCTURED_SHADOW_QA_EXTRACTOR_VERSION,
            ),
            'extractor_version' => LaboratoryResultInputHash::STRUCTURED_SHADOW_QA_EXTRACTOR_VERSION,
            'published_version_slot' => null,
            'raw_extraction_payload' => ['shadow_qa' => true, 'mode' => 'shadow_qa'],
        ]);

        return [$version, $report];
    }
}
