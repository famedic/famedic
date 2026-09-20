<?php

namespace Tests\Feature\Laboratory;

use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryResultEventType;
use App\Enums\LaboratoryResultExtractionMethod;
use App\Enums\LaboratoryResultExtractionStatus;
use App\Enums\LaboratoryResultObservationValueType;
use App\Enums\LaboratoryResultReferenceStatus;
use App\Enums\LaboratoryResultStructuredStatus;
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
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class LaboratoryResultReportPublisherTest extends TestCase
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
    public function solo_un_report_publicado_por_version_y_supersede_anterior(): void
    {
        [$version, $analyte] = $this->seedContext();

        $first = $this->createValidatedReport($version, $analyte, 'hash-first');
        $this->createObservation($first, $analyte);
        $first = app(LaboratoryResultReportPublisher::class)->publish($first);

        $second = $this->createValidatedReport($version, $analyte, 'hash-second');
        $this->createObservation($second, $analyte);
        $second = app(LaboratoryResultReportPublisher::class)->publish($second);

        $first->refresh();

        $this->assertSame(LaboratoryResultStructuredStatus::Superseded, $first->structured_status);
        $this->assertNull($first->published_version_slot);
        $this->assertSame(LaboratoryResultStructuredStatus::Published, $second->structured_status);
        $this->assertSame($version->id, $second->published_version_slot);
        $this->assertSame(1, LaboratoryResultReport::query()->activePublished()->count());

        $this->assertTrue(
            LaboratoryResultEvent::query()
                ->where('event_type', LaboratoryResultEventType::StructurePublished->value)
                ->exists()
        );
    }

    #[Test]
    public function no_publica_report_sin_observations(): void
    {
        [$version] = $this->seedContext();
        $report = $this->createValidatedReport($version, LaboratoryAnalyte::factory()->create(), 'hash-empty');

        $this->expectException(RuntimeException::class);
        app(LaboratoryResultReportPublisher::class)->publish($report);
    }

    /**
     * @return array{0: LaboratoryResultVersion, 1: LaboratoryAnalyte}
     */
    private function seedContext(): array
    {
        $user = User::query()->create([
            'name' => 'Paciente Test',
            'email' => 'patient-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);
        $customer = Customer::query()->create(['user_id' => $user->id]);

        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $customer->id,
            'brand' => LaboratoryBrand::OLAB,
            'gda_order_id' => 'ORD-'.uniqid(),
            'name' => 'Test',
            'paternal_lastname' => 'Patient',
            'maternal_lastname' => 'Lab',
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
            'name' => 'Quimica',
            'price_cents' => 10000,
        ]);

        $status = LaboratoryResultStatus::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'laboratory_purchase_item_id' => $item->id,
            'status' => 'complete',
        ]);

        $version = LaboratoryResultVersion::query()->create([
            'laboratory_result_status_id' => $status->id,
            'storage_path' => 'results/test.pdf',
            'sha256' => hash('sha256', 'pdf'),
            'source' => 'gda',
            'classification' => 'complete',
            'classification_reason' => 'test',
            'matched_rule' => 'test',
            'classifier' => 'deterministic_pdf_v1',
        ]);

        $analyte = LaboratoryAnalyte::factory()->create();

        return [$version, $analyte];
    }

    private function createValidatedReport(
        LaboratoryResultVersion $version,
        LaboratoryAnalyte $analyte,
        string $inputHash,
    ): LaboratoryResultReport {
        return LaboratoryResultReport::query()->create([
            'laboratory_purchase_id' => $version->resultStatus->laboratory_purchase_id,
            'laboratory_result_version_id' => $version->id,
            'source' => 'gda',
            'extraction_method' => LaboratoryResultExtractionMethod::PdfText,
            'extraction_status' => LaboratoryResultExtractionStatus::Extracted,
            'structured_status' => LaboratoryResultStructuredStatus::Validated,
            'observation_count' => 0,
            'input_hash' => $inputHash,
            'extractor_version' => 'RESULT_TEXT_EXTRACTOR_V1',
        ]);
    }

    private function createObservation(LaboratoryResultReport $report, LaboratoryAnalyte $analyte): void
    {
        LaboratoryResultObservation::query()->create([
            'laboratory_result_report_id' => $report->id,
            'laboratory_analyte_id' => $analyte->id,
            'analyte_code' => $analyte->code,
            'analyte_name_raw' => $analyte->canonical_name,
            'analyte_name_display' => $analyte->canonical_name,
            'numeric_value' => 95,
            'value_type' => LaboratoryResultObservationValueType::Numeric,
            'reference_status' => LaboratoryResultReferenceStatus::Normal,
            'extraction_method' => LaboratoryResultExtractionMethod::PdfText,
        ]);

        $report->update(['observation_count' => 1]);
    }
}
