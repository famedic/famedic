<?php

namespace Tests\Unit\LaboratoryResults;

use App\Models\AiPrompt;
use App\Models\LaboratoryResultVersion;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultTextExtractor;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionExtractor;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPromptDefinition;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultInputHash;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionHybridMessageBuilder;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\LaboratoryResultVisionHybridTestDoubles;
use Tests\Support\LaboratoryResultsPdfFixture;
use Tests\TestCase;

class LaboratoryResultVisionExtractorTest extends TestCase
{
    use LaboratoryResultVisionHybridTestDoubles;
    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();

        Config::set('laboratory-results.vision_extraction.enabled', true);

        Schema::dropIfExists('ai_executions');
        Schema::dropIfExists('ai_prompts');

        Schema::create('ai_prompts', function ($table) {
            $table->id();
            $table->string('key');
            $table->string('domain', 80);
            $table->unsignedInteger('version');
            $table->string('status', 40);
            $table->string('model')->nullable();
            $table->longText('system_prompt');
            $table->longText('user_prompt');
            $table->json('response_schema')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_executions', function ($table) {
            $table->id();
            $table->string('domain', 80);
            $table->string('feature', 120);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('prompt_id')->nullable();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->string('model')->nullable();
            $table->string('status', 40);
            $table->string('input_hash', 64)->nullable();
            $table->json('request_payload_redacted')->nullable();
            $table->json('response_payload')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->decimal('estimated_cost_usd', 12, 6)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        $promptRecord = LaboratoryResultVisionPromptDefinition::record(
            LaboratoryResultVisionPromptDefinition::VERSION_V2
        );

        AiPrompt::query()->create([
            'key' => $promptRecord['key'],
            'domain' => $promptRecord['domain'],
            'version' => $promptRecord['version'],
            'status' => AiPrompt::STATUS_ACTIVE,
            'model' => $promptRecord['model'],
            'system_prompt' => $promptRecord['system_prompt'],
            'user_prompt' => $promptRecord['user_prompt'],
            'response_schema' => $promptRecord['response_schema'],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_executions');
        Schema::dropIfExists('ai_prompts');
        Mockery::close();
        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function extrae_candidatos_con_json_valido(): void
    {
        $openAi = Mockery::mock(OpenAiClient::class);
        $openAi->shouldReceive('chatCompletionWithMetadata')
            ->once()
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

        $extractor = $this->makeExtractor($openAi);
        $version = $this->makeVersion();
        $pdf = LaboratoryResultsPdfFixture::binary(['Glucosa 95 mg/dL 70-100']);

        $result = $extractor->extractForVersion($version, $pdf);

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->candidates);
        $this->assertNotNull($result->aiExecution);
        $this->assertSame('succeeded', $result->aiExecution->status);
        $this->assertIsArray($result->aiExecution->response_payload['observations'] ?? null);
        $this->assertCount(1, $result->aiExecution->response_payload['observations']);
        $this->assertSame('Glucosa', $result->aiExecution->response_payload['observations'][0]['analyte_name_raw']);
        $this->assertStringNotContainsString('base64', json_encode($result->aiExecution->request_payload_redacted ?? []));
        $this->assertSame(
            LaboratoryResultInputHash::VISION_INPUT_MODE_PII_SAFE_HYBRID,
            $result->aiExecution->request_payload_redacted['input_mode'] ?? null,
        );
        $this->assertSame(
            LaboratoryResultInputHash::VISION_EXTRACTOR_VERSION,
            $result->aiExecution->request_payload_redacted['extractor_version'] ?? null,
        );
        $this->assertSame('pii_safe_raster', $result->aiExecution->request_payload_redacted['page_diagnostics'][0]['image_source'] ?? null);
        $this->assertSame('SAFE_CROP', $result->aiExecution->request_payload_redacted['page_diagnostics'][0]['pii_safety_status'] ?? null);
    }

    #[Test]
    public function usa_mensaje_hibrido_con_texto_smalo_e_imagen(): void
    {
        $openAi = Mockery::mock(OpenAiClient::class);
        $openAi->shouldReceive('chatCompletionWithMetadata')
            ->once()
            ->withArgs(function (array $messages): bool {
                $userMessage = collect($messages)->firstWhere('role', 'user');
                $content = is_array($userMessage) ? ($userMessage['content'] ?? null) : null;

                if (! is_array($content)) {
                    return false;
                }

                $textParts = array_values(array_filter(
                    $content,
                    fn (array $part): bool => ($part['type'] ?? null) === 'text',
                ));
                $imageParts = array_values(array_filter(
                    $content,
                    fn (array $part): bool => ($part['type'] ?? null) === 'image_url',
                ));

                $serializedText = json_encode($textParts);

                return str_contains($serializedText, LaboratoryResultVisionHybridMessageBuilder::SOURCE_TEXT_HEADER)
                    && count($imageParts) >= 1
                    && str_starts_with((string) ($imageParts[0]['image_url']['url'] ?? ''), 'data:image/png;base64,');
            })
            ->andReturn([
                'content' => ['reported_at' => null, 'observations' => []],
                'model' => 'gpt-4o-mini',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]);

        $result = $this->makeHybridVisionExtractor($openAi)->extractForVersion(
            $this->makeVersion(),
            LaboratoryResultsPdfFixture::binary(['Glucosa 95 mg/dL 70-100']),
        );

        $this->assertTrue($result->success);
    }

    #[Test]
    public function sin_renderer_pii_safe_falla_con_codigo_especifico(): void
    {
        $piiSafeRenderer = Mockery::mock(\App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPiiSafePageRenderer::class);
        $piiSafeRenderer->shouldReceive('isAvailable')->andReturn(false);
        $textPositionReader = Mockery::mock(\App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPdfTextPositionReader::class);
        $textPositionReader->shouldReceive('readPageWords')->andReturn([]);

        $extractor = new LaboratoryResultVisionExtractor(
            openAiClient: Mockery::mock(OpenAiClient::class),
            textExtractor: app(\App\Services\LaboratoryResults\Extraction\LaboratoryResultTextExtractor::class),
            pageSelector: new \App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPageSelector,
            hybridMessageBuilder: new LaboratoryResultVisionHybridMessageBuilder($piiSafeRenderer, $textPositionReader),
            piiSafePageRenderer: $piiSafeRenderer,
            responseParser: new \App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionResponseParser,
        );

        $result = $extractor->extractForVersion(
            $this->makeVersion(),
            LaboratoryResultsPdfFixture::binary(['Glucosa 95 mg/dL 70-100']),
        );

        $this->assertFalse($result->success);
        $this->assertSame('pii_safe_renderer_unavailable', $result->errorCode);
    }

    #[Test]
    public function pagina_unsafe_no_envia_imagen_a_openai(): void
    {
        $unsafeRenderer = Mockery::mock(\App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPiiSafePageRenderer::class);
        $unsafeRenderer->shouldReceive('isAvailable')->andReturn(true);
        $unsafeRenderer->shouldReceive('render')->andReturn(new \App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPiiSafePageRenderResult(
            piiSafetyStatus: \App\Enums\LaboratoryResultVisionPiiSafetyStatus::Unsafe,
            unsafeReason: 'table_header_not_found',
        ));
        $textPositionReader = Mockery::mock(\App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPdfTextPositionReader::class);
        $textPositionReader->shouldReceive('readPageWords')->andReturn([]);

        $openAi = Mockery::mock(OpenAiClient::class);
        $openAi->shouldReceive('chatCompletionWithMetadata')
            ->once()
            ->withArgs(function (array $messages): bool {
                $userMessage = collect($messages)->firstWhere('role', 'user');
                $content = is_array($userMessage) ? ($userMessage['content'] ?? null) : null;

                if (! is_array($content)) {
                    return false;
                }

                foreach ($content as $part) {
                    if (($part['type'] ?? null) === 'image_url') {
                        return false;
                    }
                }

                return true;
            })
            ->andReturn([
                'content' => ['reported_at' => null, 'observations' => []],
                'model' => 'gpt-4o-mini',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]);

        $extractor = new LaboratoryResultVisionExtractor(
            openAiClient: $openAi,
            textExtractor: app(\App\Services\LaboratoryResults\Extraction\LaboratoryResultTextExtractor::class),
            pageSelector: new \App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPageSelector,
            hybridMessageBuilder: new LaboratoryResultVisionHybridMessageBuilder($unsafeRenderer, $textPositionReader),
            piiSafePageRenderer: $unsafeRenderer,
            responseParser: new \App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionResponseParser,
        );

        $result = $extractor->extractForVersion(
            $this->makeVersion(),
            LaboratoryResultsPdfFixture::binary(['Glucosa 95 mg/dL 70-100']),
        );

        $this->assertTrue($result->success);
        $this->assertSame(0, $result->aiExecution?->request_payload_redacted['image_count'] ?? -1);
        $this->assertSame('skipped_unsafe', $result->aiExecution?->request_payload_redacted['page_diagnostics'][0]['image_source'] ?? null);
    }

    #[Test]
    public function maneja_error_de_api(): void
    {
        $openAi = Mockery::mock(OpenAiClient::class);
        $openAi->shouldReceive('chatCompletionWithMetadata')
            ->once()
            ->andThrow(new RuntimeException('OpenAI request failed with status 500'));

        $extractor = $this->makeExtractor($openAi);
        $version = $this->makeVersion();
        $pdf = LaboratoryResultsPdfFixture::binary(['Glucosa 95 mg/dL 70-100']);

        $result = $extractor->extractForVersion($version, $pdf);

        $this->assertFalse($result->success);
        $this->assertSame('vision_api_error', $result->errorCode);
        $this->assertSame('failed', $result->aiExecution?->status);
    }

    #[Test]
    public function vision_disabled_no_llama_api(): void
    {
        Config::set('laboratory-results.vision_extraction.enabled', false);

        $openAi = Mockery::mock(OpenAiClient::class);
        $openAi->shouldNotReceive('chatCompletionWithMetadata');

        $extractor = $this->makeExtractor($openAi);
        $result = $extractor->extractForVersion($this->makeVersion(), 'pdf');

        $this->assertFalse($result->success);
        $this->assertSame('vision_disabled', $result->errorCode);
    }

    private function makeExtractor(OpenAiClient $openAi): LaboratoryResultVisionExtractor
    {
        return $this->makeHybridVisionExtractor($openAi);
    }

    private function makeVersion(): LaboratoryResultVersion
    {
        $version = new LaboratoryResultVersion;
        $version->id = 42;
        $version->sha256 = hash('sha256', 'test-pdf');
        $version->source = 'gda';

        return $version;
    }
}
