<?php

namespace Tests\Feature\Laboratory;

use App\Enums\Gender;
use App\Enums\LaboratoryAnalyteAliasSource;
use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryResultExtractionMethod;
use App\Enums\LaboratoryResultExtractionStatus;
use App\Enums\LaboratoryResultStructuredStatus;
use App\Models\AiExecution;
use App\Models\AiPrompt;
use App\Models\Customer;
use App\Models\LaboratoryAnalyte;
use App\Models\LaboratoryAnalyteAlias;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryResultReport;
use App\Models\LaboratoryResultStatus;
use App\Models\LaboratoryResultVersion;
use App\Models\User;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultExtractionService;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionExtractor;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LaboratoryResultVisionHybridTestDoubles;
use Tests\Support\LaboratoryResultsPdfFixture;
use Tests\TestCase;

class LaboratoryResultVisionShadowModeTest extends TestCase
{
    use GdaResultsStorageIsolatedSchema;
    use LaboratoryResultVisionHybridTestDoubles;
    use StructuredResultsIsolatedSchema;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();
        Storage::fake();
        Config::set('laboratory-results.structured_extraction.enabled', true);
        Config::set('laboratory-results.structured_publication.enabled', true);
        Config::set('laboratory-results.vision_extraction.enabled', true);
        Config::set('laboratory-results.vision_extraction.shadow_mode', true);
        Config::set('laboratory-results.vision_extraction.fallback.min_observations', 99);

        $this->bootstrapIsolatedSchema();
        $this->bootstrapStructuredResultsSchema();
        $this->seedAnalytes();
        $this->seedVisionPrompt();
        $this->bindPiiSafeRendererMock();
        $this->bindVisionMock([
            [
                'analyte_name_raw' => 'Glucosa',
                'value' => '95',
                'value_type' => 'numeric',
                'unit' => 'mg/dL',
                'reference_text' => '70-100',
                'panel_name_raw' => null,
                'source_page' => 1,
                'confidence' => 0.98,
            ],
            [
                'analyte_name_raw' => 'Hemoglobina',
                'value' => '14.2',
                'value_type' => 'numeric',
                'unit' => 'g/dL',
                'reference_text' => '12-16',
                'panel_name_raw' => null,
                'source_page' => 1,
                'confidence' => 0.95,
            ],
        ]);
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
    public function shadow_mode_publica_text_y_no_publica_vision(): void
    {
        $version = $this->seedVersionWithPdf(LaboratoryResultsPdfFixture::binary(
            LaboratoryResultsPdfFixture::standardResultsLines()
        ));

        $textReport = app(LaboratoryResultExtractionService::class)->extractForVersion($version->id);

        $this->assertSame(LaboratoryResultStructuredStatus::Published, $textReport->structured_status);
        $this->assertSame(LaboratoryResultExtractionMethod::PdfText, $textReport->extraction_method);

        $visionReport = LaboratoryResultReport::query()
            ->where('extraction_method', LaboratoryResultExtractionMethod::Vision->value)
            ->first();

        $this->assertNotNull($visionReport);
        $this->assertSame(LaboratoryResultStructuredStatus::Draft, $visionReport->structured_status);
        $this->assertNull($visionReport->published_version_slot);
        $this->assertSame(1, AiExecution::query()->where('status', 'succeeded')->count());
        $this->assertNotNull($textReport->fresh()->raw_extraction_payload['vision_shadow'] ?? null);
    }

    #[Test]
    public function text_suficiente_no_llama_vision(): void
    {
        Config::set('laboratory-results.vision_extraction.fallback.min_observations', 1);
        Config::set('laboratory-results.vision_extraction.fallback.min_confidence', 0.1);

        Mockery::close();
        $openAi = Mockery::mock(OpenAiClient::class);
        $openAi->shouldNotReceive('chatCompletionWithMetadata');
        $this->app->instance(OpenAiClient::class, $openAi);

        $version = $this->seedVersionWithPdf(LaboratoryResultsPdfFixture::binary(
            LaboratoryResultsPdfFixture::standardResultsLines()
        ));

        app(LaboratoryResultExtractionService::class)->extractForVersion($version->id);

        $this->assertSame(0, LaboratoryResultReport::query()
            ->where('extraction_method', LaboratoryResultExtractionMethod::Vision->value)
            ->count());
    }

    #[Test]
    public function vision_disabled_nunca_se_llama(): void
    {
        Config::set('laboratory-results.vision_extraction.enabled', false);

        Mockery::close();
        $openAi = Mockery::mock(OpenAiClient::class);
        $openAi->shouldNotReceive('chatCompletionWithMetadata');
        $this->app->instance(OpenAiClient::class, $openAi);

        $version = $this->seedVersionWithPdf(LaboratoryResultsPdfFixture::binary(['']));

        app(LaboratoryResultExtractionService::class)->extractForVersion($version->id);

        $this->assertSame(0, AiExecution::query()->count());
    }

    #[Test]
    public function text_insuficiente_dispara_vision_cuando_esta_habilitada(): void
    {
        Config::set('laboratory-results.vision_extraction.fallback.min_characters', 500);

        $version = $this->seedVersionWithPdf(LaboratoryResultsPdfFixture::binary([
            'Glucosa 95 mg/dL 70-100',
        ], includeHeader: false));

        app(LaboratoryResultExtractionService::class)->extractForVersion($version->id);

        $this->assertSame(1, AiExecution::query()->where('status', 'succeeded')->count());
        $this->assertSame(1, LaboratoryResultReport::query()
            ->where('extraction_method', LaboratoryResultExtractionMethod::Vision->value)
            ->count());
    }

    #[Test]
    public function es_idempotente_para_misma_version_extractor_y_prompt(): void
    {
        $version = $this->seedVersionWithPdf(LaboratoryResultsPdfFixture::binary(
            LaboratoryResultsPdfFixture::standardResultsLines()
        ));

        $service = app(LaboratoryResultExtractionService::class);
        $service->extractForVersion($version->id);
        $service->extractForVersion($version->id);

        $this->assertSame(1, AiExecution::query()->where('status', 'succeeded')->count());
        $this->assertSame(1, LaboratoryResultReport::query()
            ->where('extraction_method', LaboratoryResultExtractionMethod::Vision->value)
            ->count());
    }

    #[Test]
    public function conflict_no_reemplaza_text_publicado_en_shadow_mode(): void
    {
        $this->bindVisionMock([
            [
                'analyte_name_raw' => 'Glucosa',
                'value' => '9.5',
                'value_type' => 'numeric',
                'unit' => 'mg/dL',
                'reference_text' => '70-100',
                'panel_name_raw' => null,
                'source_page' => 1,
                'confidence' => 0.98,
            ],
        ]);

        $version = $this->seedVersionWithPdf(LaboratoryResultsPdfFixture::binary(
            LaboratoryResultsPdfFixture::standardResultsLines()
        ));

        $textReport = app(LaboratoryResultExtractionService::class)->extractForVersion($version->id);

        $this->assertSame(LaboratoryResultStructuredStatus::Published, $textReport->structured_status);
        $this->assertSame('95.000000', $textReport->observations()->first()->numeric_value);

        $visionReport = LaboratoryResultReport::query()
            ->where('extraction_method', LaboratoryResultExtractionMethod::Vision->value)
            ->first();

        $this->assertSame(LaboratoryResultExtractionStatus::ManualReview, $visionReport->extraction_status);
        $this->assertNotNull($textReport->fresh()->validation_errors);
    }

    /**
     * @param  list<array<string, mixed>>  $observations
     */
    private function bindVisionMock(array $observations): void
    {
        $openAi = Mockery::mock(OpenAiClient::class);
        $openAi->shouldReceive('chatCompletionWithMetadata')
            ->andReturn([
                'content' => [
                    'reported_at' => null,
                    'observations' => $observations,
                ],
                'model' => 'gpt-4o-mini',
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 60, 'total_tokens' => 180],
            ]);
        $this->app->instance(OpenAiClient::class, $openAi);
    }

    private function seedVisionPrompt(): void
    {
        AiPrompt::query()->create([
            'key' => LaboratoryResultVisionExtractor::PROMPT_KEY,
            'domain' => LaboratoryResultVisionExtractor::DOMAIN,
            'version' => 1,
            'status' => AiPrompt::STATUS_ACTIVE,
            'model' => 'gpt-4o-mini',
            'system_prompt' => 'Extract visible lab results only.',
            'user_prompt' => 'Pages: {{page_numbers}}',
            'response_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'reported_at' => ['type' => ['string', 'null']],
                    'observations' => ['type' => 'array', 'items' => ['type' => 'object']],
                ],
                'required' => ['reported_at', 'observations'],
            ],
        ]);
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
