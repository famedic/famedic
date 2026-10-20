<?php

namespace Tests\Feature\Laboratory;

use App\Enums\Gender;
use App\Enums\LaboratoryAnalyteValueKind;
use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryResultExtractionMethod;
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
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredQaCandidateRegistry;
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredQaPersistenceService;
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredQaRunOptions;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StructuredQaShadowPersistenceTest extends TestCase
{
    use GdaResultsStorageIsolatedSchema;
    use StructuredResultsIsolatedSchema;

    private LaboratoryResultVersion $versionOne;

    private LaboratoryResultVersion $versionTwo;

    private LaboratoryPurchaseItem $itemOne;

    private LaboratoryPurchaseItem $itemTwo;

    private string $manifestPath;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();

        $this->bootstrapIsolatedSchema();
        $this->bootstrapStructuredResultsSchema();

        Config::set('laboratory-results.structured_shadow_qa.enabled', true);

        $this->seedCatalogAnalytes();
        [$this->versionOne, $this->itemOne] = $this->seedVersionWithItem('one');
        [$this->versionTwo, $this->itemTwo] = $this->seedVersionWithItem('two');
        $this->manifestPath = $this->writeTestManifest();
        Config::set('laboratory-results.structured_shadow_qa.manifest_path', $this->manifestPath);
        app(LaboratoryStructuredQaCandidateRegistry::class)->flushCache();
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
    public function persiste_candidate_shadow_qa(): void
    {
        $result = $this->service()->run(new LaboratoryStructuredQaRunOptions(dryRun: false));

        $this->assertSame(4, $result->candidatesInput);
        $this->assertSame(3, $result->validCount);
        $this->assertSame(1, $result->rejectedCount);
        $this->assertSame(3, $result->observationsPersisted);

        $report = LaboratoryResultReport::query()->shadowQa()->first();
        $this->assertNotNull($report);
        $this->assertTrue($report->isShadowQa());
        $this->assertNull($report->published_version_slot);
        $this->assertSame(LaboratoryResultStructuredStatus::Draft, $report->structured_status);
    }

    #[Test]
    public function persistencia_es_idempotente(): void
    {
        $service = $this->service();
        $first = $service->run(new LaboratoryStructuredQaRunOptions(dryRun: false));
        $second = $service->run(new LaboratoryStructuredQaRunOptions(dryRun: false));

        $this->assertSame(3, $first->observationsPersisted);
        $this->assertSame(0, $second->observationsPersisted);
        $this->assertSame(3, $second->observationsDuplicatePrevented);
        $this->assertSame(3, LaboratoryResultObservation::query()->count());
        $this->assertSame(2, LaboratoryResultReport::query()->shadowQa()->count());
    }

    #[Test]
    public function shadow_report_no_esta_publicado(): void
    {
        $this->service()->run(new LaboratoryStructuredQaRunOptions(dryRun: false));

        $this->assertSame(0, LaboratoryResultReport::query()->activePublished()->count());
        $this->assertSame(0, LaboratoryResultReport::query()->published()->count());
    }

    #[Test]
    public function registra_purchase_item_association_method(): void
    {
        $this->service()->run(new LaboratoryStructuredQaRunOptions(dryRun: false));

        $smalot = LaboratoryResultObservation::query()
            ->where('analyte_code', 'FAMEDIC_CBC_HGB')
            ->first();

        $this->assertNotNull($smalot);
        $this->assertSame($this->itemOne->id, $smalot->laboratory_purchase_item_id);
        $this->assertSame('smalot_panel_deterministic', $smalot->metadata['purchase_association_method']);
    }

    #[Test]
    public function normaliza_unidad_rbc_mill_mm3_a_catalogo(): void
    {
        $this->service()->run(new LaboratoryStructuredQaRunOptions(dryRun: false));

        $rbc = LaboratoryResultObservation::query()
            ->where('analyte_code', 'FAMEDIC_CBC_RBC')
            ->first();

        $this->assertNotNull($rbc);
        $this->assertSame('mill/mm3', $rbc->unit_raw);
        $this->assertSame('mill/µL', $rbc->unit);
    }

    #[Test]
    public function persiste_referencias_sin_calcular_out_of_range(): void
    {
        $this->service()->run(new LaboratoryStructuredQaRunOptions(dryRun: false));

        $chol = LaboratoryResultObservation::query()
            ->where('analyte_code', 'FAMEDIC_CHEM_CHOL')
            ->first();

        $this->assertNotNull($chol);
        $this->assertSame('<200', $chol->reference_text);
        $this->assertSame('200.000000', $chol->reference_high);
        $this->assertNull($chol->reference_low);
        $this->assertSame(LaboratoryResultReferenceStatus::Unknown, $chol->reference_status);
        $this->assertNull($chol->abnormal_flag);
    }

    #[Test]
    public function rechaza_candidate_invalido(): void
    {
        $result = $this->service()->run(new LaboratoryStructuredQaRunOptions(dryRun: true));

        $this->assertSame(1, $result->rejectedCount);
        $this->assertContains('laboratory_result_version_not_found', $result->rejected[0]['validation_reasons']);
    }

    #[Test]
    public function persiste_multiples_observations_por_version(): void
    {
        $this->service()->run(new LaboratoryStructuredQaRunOptions(dryRun: false));

        $report = LaboratoryResultReport::query()
            ->where('laboratory_result_version_id', $this->versionOne->id)
            ->shadowQa()
            ->first();

        $this->assertNotNull($report);
        $this->assertSame(2, $report->observations()->count());
    }

    #[Test]
    public function aisla_reports_por_version(): void
    {
        $this->service()->run(new LaboratoryStructuredQaRunOptions(dryRun: false));

        $this->assertSame(1, LaboratoryResultReport::query()->where('laboratory_result_version_id', $this->versionOne->id)->count());
        $this->assertSame(1, LaboratoryResultReport::query()->where('laboratory_result_version_id', $this->versionTwo->id)->count());
    }

    #[Test]
    public function patient_facing_query_no_incluye_shadow_reports(): void
    {
        $this->service()->run(new LaboratoryStructuredQaRunOptions(dryRun: false));

        [$purchase, $version] = $this->seedPublishedTextReportForPurchase();

        $patientReports = LaboratoryResultReport::query()
            ->where('laboratory_purchase_id', $purchase->id)
            ->activePublished()
            ->get();

        $this->assertCount(1, $patientReports);
        $this->assertSame(LaboratoryResultExtractionMethod::PdfText, $patientReports->first()->extraction_method);
        $this->assertSame(2, LaboratoryResultReport::query()->shadowQa()->count());
        $this->assertFalse($patientReports->contains(fn ($r) => $r->isShadowQa()));
    }

    #[Test]
    public function dry_run_no_escribe_db(): void
    {
        $result = $this->service()->run(new LaboratoryStructuredQaRunOptions(dryRun: true));

        $this->assertSame(3, $result->validCount);
        $this->assertSame(0, LaboratoryResultReport::query()->count());
        $this->assertSame(0, LaboratoryResultObservation::query()->count());
    }

    private function service(): LaboratoryStructuredQaPersistenceService
    {
        return app(LaboratoryStructuredQaPersistenceService::class);
    }

    private function seedCatalogAnalytes(): void
    {
        $entries = [
            ['code' => 'FAMEDIC_CBC_HGB', 'canonical_name' => 'Hemoglobina', 'default_unit' => 'g/dL'],
            ['code' => 'FAMEDIC_CBC_RBC', 'canonical_name' => 'Eritrocitos', 'default_unit' => 'mill/µL'],
            ['code' => 'FAMEDIC_CHEM_CHOL', 'canonical_name' => 'Colesterol total', 'default_unit' => 'mg/dL'],
            ['code' => 'FAMEDIC_CBC_PLT', 'canonical_name' => 'Plaquetas', 'default_unit' => 'miles/µL'],
        ];

        foreach ($entries as $entry) {
            LaboratoryAnalyte::query()->create([
                'code' => $entry['code'],
                'canonical_name' => $entry['canonical_name'],
                'default_unit' => $entry['default_unit'],
                'value_kind' => LaboratoryAnalyteValueKind::Numeric,
                'is_active' => true,
            ]);
        }
    }

    /**
     * @return array{0: LaboratoryResultVersion, 1: LaboratoryPurchaseItem}
     */
    private function seedVersionWithItem(string $suffix): array
    {
        $user = User::query()->create([
            'name' => 'QA Test',
            'email' => 'qa-'.$suffix.'-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);

        $customer = Customer::query()->create(['user_id' => $user->id]);

        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $customer->id,
            'brand' => LaboratoryBrand::OLAB,
            'gda_order_id' => 'ORD-QA-'.$suffix.'-'.uniqid(),
            'name' => 'Test',
            'paternal_lastname' => 'Patient',
            'maternal_lastname' => 'QA',
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
            'gda_id' => 'GDA-QA-'.$suffix,
            'name' => 'BIOMETRIA HEMATICA COMPLETA',
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
            'storage_path' => 'results/gda-test-'.$suffix.'.pdf',
            'sha256' => hash('sha256', 'qa-version-'.$suffix),
            'source' => 'gda',
            'classification' => 'complete',
            'classification_reason' => 'test',
            'matched_rule' => 'test',
            'classifier' => 'deterministic_pdf_v1',
            'classified_at' => now(),
        ]);

        return [$version, $item];
    }

    private function writeTestManifest(): string
    {
        $path = storage_path('framework/testing-structured-qa-manifest.json');
        @mkdir(dirname($path), 0755, true);

        $candidates = [
            $this->manifestCandidate(
                version: $this->versionOne,
                item: $this->itemOne,
                analyteCode: 'FAMEDIC_CBC_HGB',
                value: 14.4,
                unitRaw: 'g/dL',
                reference: '11.7 - 16.3',
                referenceLow: 11.7,
                referenceHigh: 16.3,
                associationMethod: 'smalot_panel_deterministic',
                isCbc: true,
            ),
            $this->manifestCandidate(
                version: $this->versionOne,
                item: $this->itemOne,
                analyteCode: 'FAMEDIC_CBC_RBC',
                value: 4.6,
                unitRaw: 'mill/mm3',
                reference: '3.9 - 5.4',
                referenceLow: 3.9,
                referenceHigh: 5.4,
                associationMethod: 'smalot_panel_deterministic',
                isCbc: true,
            ),
            $this->manifestCandidate(
                version: $this->versionTwo,
                item: $this->itemTwo,
                analyteCode: 'FAMEDIC_CHEM_CHOL',
                value: 207,
                unitRaw: 'mg/dL',
                reference: '<200',
                referenceLow: null,
                referenceHigh: 200,
                associationMethod: 'result_status_confirmed',
                isCbc: false,
                referenceClass: 'partial_numeric',
                sourcePage: 2,
            ),
            $this->manifestCandidate(
                version: $this->versionTwo,
                item: $this->itemTwo,
                analyteCode: 'FAMEDIC_CBC_PLT',
                value: 300,
                unitRaw: 'miles/uL',
                reference: '167 - 431',
                referenceLow: 167,
                referenceHigh: 431,
                associationMethod: null,
                isCbc: true,
                versionIdOverride: 99999,
            ),
        ];

        file_put_contents($path, json_encode([
            'phase' => '8C-17D-TEST',
            'candidate_count' => count($candidates),
            'candidates' => $candidates,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestCandidate(
        LaboratoryResultVersion $version,
        LaboratoryPurchaseItem $item,
        string $analyteCode,
        float $value,
        string $unitRaw,
        string $reference,
        ?float $referenceLow,
        ?float $referenceHigh,
        ?string $associationMethod,
        bool $isCbc,
        string $referenceClass = 'complete_numeric',
        int $sourcePage = 1,
        ?int $versionIdOverride = null,
    ): array {
        return [
            'physical_pdf' => basename($version->storage_path),
            'laboratory_result_version_id' => $versionIdOverride ?? $version->id,
            'analyte_code' => $analyteCode,
            'analyte_name_raw' => strtoupper($analyteCode),
            'analyte' => $analyteCode,
            'value' => $value,
            'unit_raw' => $unitRaw,
            'catalog_default_unit' => null,
            'reference_text' => $reference,
            'reference_low' => $referenceLow,
            'reference_high' => $referenceHigh,
            'reference_class' => $referenceClass,
            'value_type' => 'numeric',
            'source_page' => $sourcePage,
            'confidence' => 1,
            'purchase_item_id' => $item->id,
            'purchase_association_method' => $associationMethod,
            'is_cbc' => $isCbc,
            'identity' => ['confirmed' => true, 'evidence' => 'test'],
            'purchase_item_association' => [
                'status' => 'confirmed',
                'laboratory_purchase_item_id' => $item->id,
                'purchase_item_name' => $item->name,
            ],
            'version_association' => ['confirmed' => true],
            'structured_readiness' => 'READY_FOR_STRUCTURED_CANDIDATE',
        ];
    }

    /**
     * @return array{0: LaboratoryPurchase, 1: LaboratoryResultVersion}
     */
    private function seedPublishedTextReportForPurchase(): array
    {
        $version = $this->versionOne->loadMissing('resultStatus.laboratoryPurchase');
        $purchase = $version->resultStatus->laboratoryPurchase;

        LaboratoryResultReport::factory()->published($version)->create([
            'laboratory_purchase_id' => $purchase->id,
            'input_hash' => hash('sha256', 'published-text-report'),
            'extraction_method' => LaboratoryResultExtractionMethod::PdfText,
        ]);

        return [$purchase, $version];
    }
}
