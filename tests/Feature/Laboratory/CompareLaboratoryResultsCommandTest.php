<?php

namespace Tests\Feature\Laboratory;

use App\Enums\Gender;
use App\Enums\LaboratoryAnalyteAliasSource;
use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryResultExtractionMethod;
use App\Enums\LaboratoryResultStructuredStatus;
use App\Models\AiExecution;
use App\Models\AiPrompt;
use App\Models\Customer;
use App\Models\LaboratoryAnalyte;
use App\Models\LaboratoryAnalyteAlias;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryResultExtractionQaMetric;
use App\Models\LaboratoryResultReport;
use App\Models\LaboratoryResultStatus;
use App\Models\LaboratoryResultVersion;
use App\Models\User;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionExtractor;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LaboratoryResultsPdfFixture;
use Tests\TestCase;

class CompareLaboratoryResultsCommandTest extends TestCase
{
    use GdaResultsStorageIsolatedSchema;
    use StructuredResultsIsolatedSchema;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();
        Storage::fake();
        Config::set('laboratory-results.qa.allowed_environments', ['local', 'testing']);
        Config::set('laboratory-results.vision_extraction.enabled', true);
        Config::set('laboratory-results.vision_extraction.shadow_mode', true);
        Config::set('laboratory-results.vision_extraction.fallback.min_observations', 99);

        $this->bootstrapIsolatedSchema();
        $this->bootstrapStructuredResultsSchema();
        $this->seedAnalytes();
        $this->seedVisionPrompt();
        $this->bindVisionMock();
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
    public function command_no_permite_production(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $exitCode = Artisan::call('laboratory:results:compare', ['version' => 1]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('cannot run in production', Artisan::output());
    }

    #[Test]
    public function command_genera_comparison_summary_sin_publicar_vision(): void
    {
        app()->detectEnvironment(fn () => 'testing');

        $version = $this->seedVersionWithPdf(LaboratoryResultsPdfFixture::binary(
            LaboratoryResultsPdfFixture::standardResultsLines()
        ));

        $exitCode = Artisan::call('laboratory:results:compare', [
            'version' => $version->id,
            '--synthetic' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();

        $this->assertStringContainsString('Result version: '.$version->id, $output);
        $this->assertStringContainsString('MATCH:', $output);
        $this->assertStringContainsString('SHADOW_ONLY', $output);
        $this->assertStringContainsString('NOT published', $output);

        $this->assertSame(1, LaboratoryResultExtractionQaMetric::query()->count());
        $metric = LaboratoryResultExtractionQaMetric::query()->first();
        $this->assertNotNull($metric->summary);
        $this->assertSame(0, LaboratoryResultReport::query()
            ->where('extraction_method', LaboratoryResultExtractionMethod::Vision->value)
            ->where('structured_status', LaboratoryResultStructuredStatus::Published->value)
            ->count());
    }

    #[Test]
    public function command_no_reemplaza_text_publicado(): void
    {
        app()->detectEnvironment(fn () => 'testing');

        $version = $this->seedVersionWithPdf(LaboratoryResultsPdfFixture::binary(
            LaboratoryResultsPdfFixture::standardResultsLines()
        ));

        $version->load('resultStatus');

        $textReport = LaboratoryResultReport::query()->create([
            'laboratory_purchase_id' => $version->resultStatus->laboratory_purchase_id,
            'laboratory_result_version_id' => $version->id,
            'source' => 'gda',
            'extraction_method' => LaboratoryResultExtractionMethod::PdfText->value,
            'extraction_status' => 'extracted',
            'structured_status' => LaboratoryResultStructuredStatus::Published->value,
            'observation_count' => 3,
            'input_hash' => hash('sha256', 'text-report'),
            'extractor_version' => 'RESULT_TEXT_EXTRACTOR_V1',
            'published_version_slot' => $version->id,
            'published_at' => now(),
        ]);

        Artisan::call('laboratory:results:compare', ['version' => $version->id, '--force-vision' => true]);

        $fresh = $textReport->fresh();
        $this->assertSame(LaboratoryResultStructuredStatus::Published, $fresh->structured_status);
        $this->assertSame($version->id, $fresh->published_version_slot);
    }

    #[Test]
    public function ai_execution_no_contiene_pdf_base64_ni_pii(): void
    {
        app()->detectEnvironment(fn () => 'testing');

        $version = $this->seedVersionWithPdf(LaboratoryResultsPdfFixture::binary(
            LaboratoryResultsPdfFixture::standardResultsLines(),
            includeHeader: false,
        ));

        Artisan::call('laboratory:results:compare', ['version' => $version->id]);

        $execution = AiExecution::query()->latest('id')->first();
        $this->assertNotNull($execution);

        $payloadJson = json_encode($execution->request_payload_redacted);
        $this->assertIsString($payloadJson);
        $this->assertStringNotContainsString('base64', strtolower($payloadJson));
        $this->assertStringNotContainsString('curp', strtolower($payloadJson));
        $this->assertStringNotContainsString('@test.local', strtolower($payloadJson));
        $this->assertArrayHasKey('pages_sent', $execution->request_payload_redacted ?? []);
    }

    #[Test]
    public function shadow_mode_permanece_activo(): void
    {
        app()->detectEnvironment(fn () => 'testing');

        $version = $this->seedVersionWithPdf(LaboratoryResultsPdfFixture::binary([
            'Glucosa        95       mg/dL       70-100',
        ]));

        Artisan::call('laboratory:results:compare', ['version' => $version->id]);

        $metric = LaboratoryResultExtractionQaMetric::query()->first();
        $this->assertTrue($metric->shadow_mode);
        $this->assertTrue((bool) config('laboratory-results.vision_extraction.shadow_mode'));
    }

    private function bindVisionMock(): void
    {
        $openAi = Mockery::mock(OpenAiClient::class);
        $openAi->shouldReceive('chatCompletionWithMetadata')
            ->andReturn([
                'content' => [
                    'reported_at' => null,
                    'observations' => [
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
                    ],
                ],
                'model' => 'gpt-4o-mini',
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
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
    }

    private function seedVersionWithPdf(string $binary): LaboratoryResultVersion
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

        $path = 'results/gda-'.$purchase->id.'-qa.pdf';
        Storage::put($path, $binary);

        return LaboratoryResultVersion::query()->create([
            'laboratory_result_status_id' => $status->id,
            'storage_path' => $path,
            'sha256' => hash('sha256', $binary),
            'source' => 'gda',
            'classification' => 'complete',
            'classification_reason' => 'test',
            'matched_rule' => 'test',
            'classifier' => 'deterministic_pdf_v1',
            'classified_at' => now(),
        ]);
    }
}
