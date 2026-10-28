<?php

namespace Tests\Unit\LaboratoryResults\StructuredQa;

use App\Enums\Gender;
use App\Enums\LaboratoryAnalyteValueKind;
use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryResultEventType;
use App\Enums\LaboratoryResultExtractionMethod;
use App\Enums\LaboratoryResultObservationValueType;
use App\Enums\LaboratoryResultReferenceStatus;
use App\Enums\LaboratoryResultStructuredStatus;
use App\Enums\LaboratoryStructuredResultPublicationApprovalStatus;
use App\Enums\LaboratoryStructuredResultPromotionStatus;
use App\Models\Customer;
use App\Models\LaboratoryAnalyte;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryResultEvent;
use App\Models\LaboratoryResultObservation;
use App\Models\LaboratoryResultReport;
use App\Models\LaboratoryResultStatus;
use App\Models\LaboratoryResultVersion;
use App\Models\User;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultReportPublisher;
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredResultApprovalException;
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredResultApprovalService;
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredResultPublicationApproval;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Laboratory\GdaResultsStorageIsolatedSchema;
use Tests\Feature\Laboratory\StructuredResultsIsolatedSchema;
use Tests\TestCase;

class LaboratoryStructuredResultApprovalServiceTest extends TestCase
{
    use GdaResultsStorageIsolatedSchema;
    use StructuredResultsIsolatedSchema;

    private LaboratoryStructuredResultApprovalService $service;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();
        $this->bootstrapIsolatedSchema();
        $this->bootstrapStructuredResultsSchema();
        $this->service = app(LaboratoryStructuredResultApprovalService::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->tearDownStructuredResultsSchema();
        $this->tearDownIsolatedSchema();
        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function validated_puede_aprobarse(): void
    {
        [$report, $admin] = $this->seedValidatedShadowReport();

        $result = $this->service->approve($report, $admin, 'QA review completed for pilot publication.');

        $this->assertFalse($result->idempotent);
        $this->assertSame(LaboratoryStructuredResultPublicationApprovalStatus::Approved, $result->status);
        $this->assertSame('QA review completed for pilot publication.', $result->approval['reason']);
        $this->assertSame($admin->id, $result->approval['approved_by_user_id']);

        $this->assertSame(1, LaboratoryResultEvent::query()
            ->where('event_type', LaboratoryResultEventType::StructuredResultApproved->value)
            ->count());
    }

    #[Test]
    public function shadow_no_validated_es_bloqueado(): void
    {
        [$report, $admin] = $this->seedValidatedShadowReport(promotionStatus: 'shadow');

        $this->expectException(LaboratoryStructuredResultApprovalException::class);
        $this->service->approve($report, $admin);
    }

    #[Test]
    public function needs_review_es_bloqueado(): void
    {
        [$report, $admin] = $this->seedValidatedShadowReport(promotionStatus: 'needs_review');

        $this->expectException(LaboratoryStructuredResultApprovalException::class);
        $this->service->approve($report, $admin);
    }

    #[Test]
    public function promotion_rejected_es_bloqueado(): void
    {
        [$report, $admin] = $this->seedValidatedShadowReport(promotionStatus: 'rejected');

        $this->expectException(LaboratoryStructuredResultApprovalException::class);
        $this->service->approve($report, $admin);
    }

    #[Test]
    public function pii_unsafe_es_bloqueado(): void
    {
        [$report, $admin] = $this->seedValidatedShadowReport(extraMetadata: ['pii_unsafe' => true]);

        $this->expectException(LaboratoryStructuredResultApprovalException::class);
        $this->service->approve($report, $admin);
    }

    #[Test]
    public function aprobacion_es_idempotente(): void
    {
        [$report, $admin] = $this->seedValidatedShadowReport();

        $first = $this->service->approve($report, $admin, 'first');
        $second = $this->service->approve($report->fresh(), $admin, 'second');

        $this->assertFalse($first->idempotent);
        $this->assertTrue($second->idempotent);
        $this->assertSame('first', $second->approval['reason']);
        $this->assertSame(1, LaboratoryResultEvent::query()
            ->where('event_type', LaboratoryResultEventType::StructuredResultApproved->value)
            ->count());
    }

    #[Test]
    public function rejected_no_puede_aprobarse(): void
    {
        [$report, $admin] = $this->seedValidatedShadowReport();

        $this->service->reject($report, $admin, 'Not ready for publication');

        $this->expectException(LaboratoryStructuredResultApprovalException::class);
        $this->service->approve($report->fresh(), $admin);
    }

    #[Test]
    public function published_version_slot_permanece_null(): void
    {
        [$report, $admin] = $this->seedValidatedShadowReport();

        $this->service->approve($report, $admin);

        $report->refresh();
        $this->assertNull($report->published_version_slot);
        $this->assertNotSame(LaboratoryResultStructuredStatus::Published, $report->structured_status);
    }

    #[Test]
    public function active_published_excluye_report(): void
    {
        [$report, $admin] = $this->seedValidatedShadowReport();

        $this->service->approve($report, $admin);

        $this->assertSame(0, LaboratoryResultReport::query()->activePublished()->count());
    }

    #[Test]
    public function publisher_nunca_es_llamado(): void
    {
        [$report, $admin] = $this->seedValidatedShadowReport();

        $publisher = Mockery::mock(LaboratoryResultReportPublisher::class);
        $publisher->shouldNotReceive('publish');
        $this->app->instance(LaboratoryResultReportPublisher::class, $publisher);

        app(LaboratoryStructuredResultApprovalService::class)->approve($report, $admin);

        $this->assertSame(
            'approved',
            LaboratoryStructuredResultPublicationApproval::read($report->fresh())['approval_status'],
        );
    }

    /**
     * @return array{0: LaboratoryResultReport, 1: User}
     */
    private function seedValidatedShadowReport(
        string $promotionStatus = 'validated',
        array $extraMetadata = [],
    ): array {
        if (LaboratoryAnalyte::query()->count() === 0) {
            LaboratoryAnalyte::query()->create([
                'code' => 'FAMEDIC_CBC_HGB',
                'canonical_name' => 'Hemoglobina',
                'default_unit' => 'g/dL',
                'value_kind' => LaboratoryAnalyteValueKind::Numeric,
                'is_active' => true,
            ]);
        }

        $admin = User::query()->create([
            'name' => 'Admin Approver',
            'email' => 'approver-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);

        $user = User::query()->create([
            'name' => 'Patient',
            'email' => 'patient-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);
        $customer = Customer::query()->create(['user_id' => $user->id]);

        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $customer->id,
            'brand' => LaboratoryBrand::OLAB,
            'gda_order_id' => 'ORD-APPROVE',
            'name' => 'Test',
            'paternal_lastname' => 'Patient',
            'maternal_lastname' => 'Approve',
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
            'gda_id' => 'GDA-APPROVE',
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
            'storage_path' => 'results/gda-approve-test.pdf',
            'sha256' => hash('sha256', 'approve-version'),
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
            'structured_status' => LaboratoryResultStructuredStatus::Validated,
            'observation_count' => 1,
            'input_hash' => hash('sha256', 'approve-report'),
            'published_version_slot' => null,
            'raw_extraction_payload' => ['shadow_qa' => true, 'mode' => 'shadow_qa'],
        ]);

        $metadata = array_merge([
            'shadow_qa' => true,
            'purchase_association_method' => 'result_status_confirmed',
            'identity_evidence' => 'test',
            'reference_evaluation' => ['evaluated' => true],
            'promotion_evaluation' => [
                'gate_version' => 'promotion_gate_v1',
                'promotion_status' => $promotionStatus,
                'reason_codes' => [],
                'reasons' => [],
            ],
        ], $extraMetadata);

        if ($promotionStatus === LaboratoryStructuredResultPromotionStatus::Validated->value) {
            // keep as is
        }

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
            'metadata' => $metadata,
        ]);

        return [$report, $admin];
    }
}
