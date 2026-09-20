<?php

namespace Tests\Feature\Laboratory;

use App\Enums\Gender;
use App\Enums\LaboratoryAnalyteAliasSource;
use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryResultEventType;
use App\Enums\LaboratoryResultExtractionStatus;
use App\Enums\LaboratoryResultStructuredStatus;
use App\Jobs\ExtractLaboratoryResultReportJob;
use App\Models\Customer;
use App\Models\LaboratoryAnalyte;
use App\Models\LaboratoryAnalyteAlias;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryResultEvent;
use App\Models\LaboratoryResultReport;
use App\Models\LaboratoryResultStatus;
use App\Models\LaboratoryResultVersion;
use App\Models\User;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LaboratoryResultsPdfFixture;
use Tests\TestCase;

class LaboratoryResultExtractionJobTest extends TestCase
{
    use GdaResultsStorageIsolatedSchema;
    use StructuredResultsIsolatedSchema;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();
        Storage::fake();
        Config::set('laboratory-results.structured_extraction.enabled', true);
        $this->bootstrapIsolatedSchema();
        $this->bootstrapStructuredResultsSchema();
        $this->seedAnalytes();
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
    public function pdf_valido_genera_report_publicado_con_observations(): void
    {
        $version = $this->seedVersionWithPdf(LaboratoryResultsPdfFixture::binary(
            LaboratoryResultsPdfFixture::standardResultsLines()
        ));

        $report = app(LaboratoryResultExtractionService::class)->extractForVersion($version->id);

        $this->assertNotNull($report);
        $this->assertSame(LaboratoryResultStructuredStatus::Published, $report->structured_status);
        $this->assertSame(LaboratoryResultExtractionStatus::Partial, $report->extraction_status);
        $this->assertGreaterThanOrEqual(1, $report->observations()->count());
        $this->assertNotNull($report->published_version_slot);

        $glucose = $report->observations()->where('analyte_code', 'glucose_serum')->first();
        $this->assertNotNull($glucose);
        $this->assertSame('95.000000', $glucose->numeric_value);
        $this->assertSame('normal', $glucose->reference_status->value);
    }

    #[Test]
    public function es_idempotente_para_misma_version_e_input_hash(): void
    {
        $version = $this->seedVersionWithPdf(LaboratoryResultsPdfFixture::binary([
            'Glucosa 95 mg/dL 70-100',
        ]));

        $service = app(LaboratoryResultExtractionService::class);
        $first = $service->extractForVersion($version->id);
        $second = $service->extractForVersion($version->id);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, LaboratoryResultReport::query()->count());
    }

    #[Test]
    public function pdf_inexistente_registra_fallo_sin_romper(): void
    {
        $version = $this->seedVersionWithPdf(null);

        $report = app(LaboratoryResultExtractionService::class)->extractForVersion($version->id);

        $this->assertSame(LaboratoryResultExtractionStatus::Failed, $report->extraction_status);
        $this->assertTrue(
            LaboratoryResultEvent::query()
                ->where('event_type', LaboratoryResultEventType::ExtractionFailed->value)
                ->exists()
        );
    }

    #[Test]
    public function job_respeta_feature_flag_apagado(): void
    {
        Config::set('laboratory-results.structured_extraction.enabled', false);
        $version = $this->seedVersionWithPdf(LaboratoryResultsPdfFixture::binary([
            'Glucosa 95 mg/dL 70-100',
        ]));

        (new ExtractLaboratoryResultReportJob($version->id))->handle(app(LaboratoryResultExtractionService::class));

        $this->assertSame(0, LaboratoryResultReport::query()->count());
    }

    private function seedAnalytes(): void
    {
        $glucose = LaboratoryAnalyte::factory()->create([
            'code' => 'glucose_serum',
            'canonical_name' => 'Glucosa en suero',
        ]);
        LaboratoryAnalyteAlias::query()->create([
            'laboratory_analyte_id' => $glucose->id,
            'alias_normalized' => 'glucosa',
            'alias_raw' => 'Glucosa',
            'source' => LaboratoryAnalyteAliasSource::Manual,
        ]);

        $hemoglobin = LaboratoryAnalyte::factory()->create([
            'code' => 'hemoglobin',
            'canonical_name' => 'Hemoglobina',
        ]);
        LaboratoryAnalyteAlias::query()->create([
            'laboratory_analyte_id' => $hemoglobin->id,
            'alias_normalized' => 'hemoglobina',
            'source' => LaboratoryAnalyteAliasSource::Manual,
        ]);

        $urea = LaboratoryAnalyte::factory()->create(['code' => 'urea', 'canonical_name' => 'Urea']);
        LaboratoryAnalyteAlias::query()->create([
            'laboratory_analyte_id' => $urea->id,
            'alias_normalized' => 'urea',
            'source' => LaboratoryAnalyteAliasSource::Manual,
        ]);

        $creatinine = LaboratoryAnalyte::factory()->create(['code' => 'creatinine', 'canonical_name' => 'Creatinina']);
        LaboratoryAnalyteAlias::query()->create([
            'laboratory_analyte_id' => $creatinine->id,
            'alias_normalized' => 'creatinina',
            'source' => LaboratoryAnalyteAliasSource::Manual,
        ]);
    }

    private function seedVersionWithPdf(?string $binary): LaboratoryResultVersion
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

        $path = 'results/gda-'.$purchase->id.'-test.pdf';

        if ($binary !== null) {
            Storage::put($path, $binary);
        }

        return LaboratoryResultVersion::query()->create([
            'laboratory_result_status_id' => $status->id,
            'storage_path' => $path,
            'sha256' => hash('sha256', $binary ?? 'missing'),
            'source' => 'gda',
            'classification' => 'complete',
            'classification_reason' => 'test',
            'matched_rule' => 'test',
            'classifier' => 'deterministic_pdf_v1',
            'classified_at' => now(),
        ]);
    }
}
