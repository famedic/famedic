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
use App\Services\LaboratoryResults\StructuredQa\LaboratoryPatientStructuredResultQuery;
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredResultApprovalService;
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredResultPublicationException;
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredResultPublicationService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Laboratory\GdaResultsStorageIsolatedSchema;
use Tests\Feature\Laboratory\StructuredResultsIsolatedSchema;
use Tests\TestCase;

class LaboratoryStructuredResultPublicationServiceTest extends TestCase
{
    use GdaResultsStorageIsolatedSchema;
    use StructuredResultsIsolatedSchema;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();
        $this->bootstrapIsolatedSchema();
        $this->bootstrapStructuredResultsSchema();
        Config::set('laboratory-results.structured_publication.enabled', true);
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
    public function validated_y_aprobado_puede_publicarse(): void
    {
        [$report, $admin] = $this->seedApprovedShadowReport(approve: false);

        app(LaboratoryStructuredResultApprovalService::class)->approve($report, $admin, 'ok');

        $result = app(LaboratoryStructuredResultPublicationService::class)->publish($report->fresh(), $admin);

        $this->assertTrue($result->published);
        $this->assertNotNull($result->report->published_version_slot);
        $this->assertSame(LaboratoryResultStructuredStatus::Published, $result->report->structured_status);
        $this->assertTrue(
            LaboratoryResultEvent::query()
                ->where('event_type', LaboratoryResultEventType::StructuredResultControlledPublished->value)
                ->exists()
        );
    }

    #[Test]
    public function sin_aprobacion_es_bloqueado(): void
    {
        [$report, $admin] = $this->seedApprovedShadowReport(approve: false);

        $this->expectException(LaboratoryStructuredResultPublicationException::class);
        app(LaboratoryStructuredResultPublicationService::class)->publish($report, $admin);
    }

    #[Test]
    public function feature_flag_disabled_es_bloqueado(): void
    {
        Config::set('laboratory-results.structured_publication.enabled', false);
        [$report, $admin] = $this->seedApprovedShadowReport(approve: false);
        app(LaboratoryStructuredResultApprovalService::class)->approve($report, $admin);

        $this->expectException(LaboratoryStructuredResultPublicationException::class);
        app(LaboratoryStructuredResultPublicationService::class)->publish($report->fresh(), $admin);
    }

    #[Test]
    public function slot_publicado_ocupado_es_bloqueado(): void
    {
        [$report, $admin, $version] = $this->seedApprovedShadowReport(returnVersion: true);
        app(LaboratoryStructuredResultApprovalService::class)->approve($report, $admin);

        LaboratoryResultReport::query()->create([
            'laboratory_purchase_id' => $report->laboratory_purchase_id,
            'laboratory_result_version_id' => $version->id,
            'source' => 'gda',
            'extraction_method' => LaboratoryResultExtractionMethod::PdfText,
            'extraction_status' => \App\Enums\LaboratoryResultExtractionStatus::Extracted,
            'structured_status' => LaboratoryResultStructuredStatus::Published,
            'observation_count' => 1,
            'input_hash' => hash('sha256', 'existing-published'),
            'published_version_slot' => $version->id,
        ]);

        $this->expectException(LaboratoryStructuredResultPublicationException::class);
        app(LaboratoryStructuredResultPublicationService::class)->publish($report->fresh(), $admin);
    }

    #[Test]
    public function contenido_cambiado_despues_de_aprobacion_es_bloqueado(): void
    {
        [$report, $admin] = $this->seedApprovedShadowReport(approve: false);
        app(LaboratoryStructuredResultApprovalService::class)->approve($report, $admin);

        LaboratoryResultObservation::query()->first()->update(['numeric_value' => 99.9]);

        $this->expectException(LaboratoryStructuredResultPublicationException::class);
        app(LaboratoryStructuredResultPublicationService::class)->publish($report->fresh(), $admin);
    }

    #[Test]
    public function publicacion_es_idempotente(): void
    {
        [$report, $admin] = $this->seedApprovedShadowReport(approve: false);
        app(LaboratoryStructuredResultApprovalService::class)->approve($report, $admin);

        $service = app(LaboratoryStructuredResultPublicationService::class);
        $first = $service->publish($report->fresh(), $admin);
        $second = $service->publish($first->report->fresh(), $admin);

        $this->assertFalse($first->idempotent);
        $this->assertTrue($second->idempotent);
        $this->assertSame(1, LaboratoryResultReport::query()->activePublished()->count());
    }

    #[Test]
    public function publisher_es_invocado_exactamente_una_vez(): void
    {
        [$report, $admin] = $this->seedApprovedShadowReport(approve: false);
        app(LaboratoryStructuredResultApprovalService::class)->approve($report, $admin);

        $realPublisher = app(LaboratoryResultReportPublisher::class);
        $publisher = Mockery::mock(LaboratoryResultReportPublisher::class);
        $publisher->shouldReceive('publish')->once()->andReturnUsing(
            fn (LaboratoryResultReport $r) => $realPublisher->publish($r)
        );
        $this->app->instance(LaboratoryResultReportPublisher::class, $publisher);

        app(LaboratoryStructuredResultPublicationService::class)->publish($report->fresh(), $admin);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function paciente_correcto_ve_resultado_publicado(): void
    {
        [$report, $admin, $purchase] = $this->seedApprovedShadowReport(returnPurchase: true);
        app(LaboratoryStructuredResultApprovalService::class)->approve($report, $admin);
        app(LaboratoryStructuredResultPublicationService::class)->publish($report->fresh(), $admin);

        $query = app(LaboratoryPatientStructuredResultQuery::class);
        $this->assertTrue($query->purchaseHasActivePublishedReport($purchase, $report->id));
        $this->assertSame(1, $query->activePublishedReportsForPurchase($purchase)->count());
    }

    #[Test]
    public function otro_paciente_no_ve_resultado(): void
    {
        [$report, $admin, $purchase] = $this->seedApprovedShadowReport(returnPurchase: true);
        app(LaboratoryStructuredResultApprovalService::class)->approve($report, $admin);
        app(LaboratoryStructuredResultPublicationService::class)->publish($report->fresh(), $admin);

        $otherUser = User::query()->create([
            'name' => 'Other',
            'email' => 'other-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);
        $otherCustomer = Customer::query()->create(['user_id' => $otherUser->id]);
        $otherPurchase = LaboratoryPurchase::query()->create([
            'customer_id' => $otherCustomer->id,
            'brand' => LaboratoryBrand::OLAB,
            'gda_order_id' => 'ORD-OTHER',
            'name' => 'Other',
            'paternal_lastname' => 'Patient',
            'maternal_lastname' => 'Other',
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

        $query = app(LaboratoryPatientStructuredResultQuery::class);
        $this->assertFalse($query->purchaseHasActivePublishedReport($otherPurchase, $report->id));
        $this->assertSame(0, $query->activePublishedReportsForPurchase($otherPurchase)->count());
        $this->assertTrue($query->purchaseHasActivePublishedReport($purchase, $report->id));
    }

    /**
     * @return array{0: LaboratoryResultReport, 1: User, 2?: LaboratoryResultVersion, 3?: LaboratoryPurchase}
     */
    private function seedApprovedShadowReport(
        bool $approve = true,
        bool $returnVersion = false,
        bool $returnPurchase = false,
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
            'name' => 'Publisher Admin',
            'email' => 'publisher-'.uniqid().'@test.local',
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
            'gda_order_id' => 'ORD-PUB',
            'name' => 'Test',
            'paternal_lastname' => 'Patient',
            'maternal_lastname' => 'Pub',
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
            'gda_id' => 'GDA-PUB',
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
            'storage_path' => 'results/gda-pub-test.pdf',
            'sha256' => hash('sha256', 'pub-version'),
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
            'input_hash' => hash('sha256', 'pub-report'),
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
                'promotion_evaluation' => [
                    'gate_version' => 'promotion_gate_v1',
                    'promotion_status' => 'validated',
                    'reason_codes' => [],
                    'reasons' => [],
                ],
            ],
        ]);

        if ($approve) {
            $payload = $report->raw_extraction_payload;
            $payload['publication_approval'] = [
                'approval_status' => LaboratoryStructuredResultPublicationApprovalStatus::Approved->value,
                'approved_by_user_id' => $admin->id,
                'approved_at' => now()->toIso8601String(),
            ];
            $report->update(['raw_extraction_payload' => $payload]);
        }

        $result = [$report, $admin];
        if ($returnVersion) {
            $result[] = $version;
        }
        if ($returnPurchase) {
            $result[] = $purchase;
        }

        return $result;
    }
}
