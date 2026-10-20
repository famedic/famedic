<?php

namespace Tests\Feature\Laboratory;

use App\Enums\Gender;
use App\Enums\LaboratoryAnalyteValueKind;
use App\Enums\LaboratoryBrand;
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
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredQaPromotionService;
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredQaRunOptions;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StructuredQaPromotionIntegrationTest extends TestCase
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
    public function promotion_gate_persiste_metadata_y_mantiene_shadow(): void
    {
        $this->seedShadowObservation();

        $result = app(LaboratoryStructuredQaPromotionService::class)->evaluate(
            new LaboratoryStructuredQaRunOptions(dryRun: false, evaluatePromotion: true),
        );

        $this->assertSame(1, $result->candidatesInput);
        $this->assertSame(1, $result->updatedCount);

        $observation = LaboratoryResultObservation::query()->first();
        $this->assertNotNull($observation->metadata['promotion_evaluation']['promotion_status']);

        $report = $observation->report;
        $this->assertTrue($report->isShadowQa());
        $this->assertNull($report->published_version_slot);
        $this->assertNotSame(LaboratoryResultStructuredStatus::Published, $report->structured_status);
    }

    #[Test]
    public function promotion_es_idempotente(): void
    {
        $this->seedShadowObservation();
        $service = app(LaboratoryStructuredQaPromotionService::class);
        $options = new LaboratoryStructuredQaRunOptions(dryRun: false, evaluatePromotion: true);

        $first = $service->evaluate($options);
        $second = $service->evaluate($options);

        $this->assertSame(1, $first->updatedCount);
        $this->assertSame(0, $second->updatedCount);
        $this->assertSame(1, $second->duplicatePrevented);
    }

    #[Test]
    public function active_published_excluye_shadow(): void
    {
        $this->seedShadowObservation();

        app(LaboratoryStructuredQaPromotionService::class)->evaluate(
            new LaboratoryStructuredQaRunOptions(dryRun: false, evaluatePromotion: true),
        );

        $this->assertSame(0, LaboratoryResultReport::query()->activePublished()->count());
        $this->assertSame(1, LaboratoryResultReport::query()->shadowQa()->count());
    }

    private function seedShadowObservation(): void
    {
        LaboratoryAnalyte::query()->create([
            'code' => 'FAMEDIC_CBC_HGB',
            'canonical_name' => 'Hemoglobina',
            'default_unit' => 'g/dL',
            'value_kind' => LaboratoryAnalyteValueKind::Numeric,
            'is_active' => true,
        ]);

        $user = User::query()->create([
            'name' => 'Test',
            'email' => 'promo-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);
        $customer = Customer::query()->create(['user_id' => $user->id]);

        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $customer->id,
            'brand' => LaboratoryBrand::OLAB,
            'gda_order_id' => 'ORD-PROMO',
            'name' => 'Test',
            'paternal_lastname' => 'Patient',
            'maternal_lastname' => 'Promo',
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
            'gda_id' => 'GDA-PROMO',
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
            'storage_path' => 'results/gda-promo-test.pdf',
            'sha256' => hash('sha256', 'promo'),
            'source' => 'gda',
            'classification' => 'complete',
            'classification_reason' => 'test',
            'matched_rule' => 'test',
            'classifier' => 'deterministic_pdf_v1',
            'classified_at' => now(),
        ]);

        $analyte = LaboratoryAnalyte::query()->first();

        $report = LaboratoryResultReport::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'laboratory_result_version_id' => $version->id,
            'source' => \App\Enums\LaboratoryResultReportSource::Gda,
            'extraction_method' => LaboratoryResultExtractionMethod::Vision,
            'extraction_status' => \App\Enums\LaboratoryResultExtractionStatus::Extracted,
            'structured_status' => LaboratoryResultStructuredStatus::Draft,
            'observation_count' => 1,
            'input_hash' => hash('sha256', 'promo-report'),
            'published_version_slot' => null,
            'raw_extraction_payload' => ['shadow_qa' => true, 'mode' => 'shadow_qa'],
        ]);

        LaboratoryResultObservation::query()->create([
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
            'reference_status' => LaboratoryResultReferenceStatus::Normal,
            'laboratory_purchase_item_id' => $item->id,
            'extraction_method' => LaboratoryResultExtractionMethod::Vision,
            'confidence' => 0.99,
            'metadata' => [
                'shadow_qa' => true,
                'purchase_association_method' => 'result_status_confirmed',
                'identity_evidence' => 'test',
                'reference_evaluation' => ['evaluated' => true],
            ],
        ]);
    }
}
