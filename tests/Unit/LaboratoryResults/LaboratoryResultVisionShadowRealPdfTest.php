<?php

namespace Tests\Unit\LaboratoryResults;

use App\Models\AiExecution;
use App\Models\AiPrompt;
use App\Models\LaboratoryResultVersion;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultInputHash;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionExtractor;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionHybridMessageBuilder;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPromptDefinition;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LaboratoryResultVisionHybridTestDoubles;
use Tests\Support\LaboratoryResultsPdfFixture;
use Tests\TestCase;

class LaboratoryResultVisionShadowRealPdfTest extends TestCase
{
    use LaboratoryResultVisionHybridTestDoubles;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();

        Config::set('laboratory-results.vision_extraction.enabled', true);
        Config::set('laboratory-results.vision_extraction.shadow_mode', true);
        Config::set(
            'laboratory-results.vision_extraction.extractor_version',
            LaboratoryResultInputHash::VISION_EXTRACTOR_VERSION,
        );

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
            LaboratoryResultVisionPromptDefinition::VERSION_V3
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
    public function shadow_mode_no_reutiliza_ai_execution_gd_legacy(): void
    {
        $version = $this->makeVersion();
        $prompt = AiPrompt::query()->firstOrFail();
        $legacyHash = LaboratoryResultInputHash::compute(
            $version->sha256,
            LaboratoryResultInputHash::VISION_EXTRACTOR_GD_LEGACY,
            $prompt->version,
        );

        AiExecution::query()->create([
            'domain' => LaboratoryResultVisionExtractor::DOMAIN,
            'feature' => LaboratoryResultVisionExtractor::FEATURE,
            'subject_type' => $version->getMorphClass(),
            'subject_id' => $version->id,
            'prompt_id' => $prompt->id,
            'prompt_version' => $prompt->version,
            'model' => 'gpt-4o-mini',
            'status' => AiExecution::STATUS_SUCCEEDED,
            'input_hash' => $legacyHash,
            'request_payload_redacted' => [
                'input_mode' => 'gd_imagestring_legacy',
                'pages_sent' => [1],
            ],
            'response_payload' => [
                'reported_at' => null,
                'observations' => [
                    [
                        'analyte_name_raw' => 'Glucosa',
                        'value' => '1',
                        'value_type' => 'numeric',
                        'unit' => 'mg/dL',
                        'reference_text' => null,
                        'panel_name_raw' => null,
                        'source_page' => 1,
                        'confidence' => 0.5,
                    ],
                ],
                'observation_count' => 1,
            ],
            'duration_ms' => 5,
        ]);

        $openAi = Mockery::mock(OpenAiClient::class);
        $openAi->shouldReceive('chatCompletionWithMetadata')->once()->andReturn([
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
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]);

        $result = $this->makeHybridVisionExtractor($openAi)->extractForVersion(
            $version,
            LaboratoryResultsPdfFixture::binary(['Glucosa 95 mg/dL 70-100']),
        );

        $this->assertFalse($result->skippedIdempotent);
        $this->assertNotSame($legacyHash, $result->inputHash);
        $this->assertSame(2, AiExecution::query()->count());
    }

    #[Test]
    public function request_payload_registra_modo_hibrido_oficial(): void
    {
        $openAi = Mockery::mock(OpenAiClient::class);
        $openAi->shouldReceive('chatCompletionWithMetadata')->once()->andReturn([
            'content' => ['reported_at' => null, 'observations' => []],
            'model' => 'gpt-4o-mini',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]);

        $result = $this->makeHybridVisionExtractor($openAi)->extractForVersion(
            $this->makeVersion(),
            LaboratoryResultsPdfFixture::binary(['Glucosa 95 mg/dL 70-100']),
        );

        $payload = $result->aiExecution?->request_payload_redacted ?? [];

        $this->assertSame(LaboratoryResultInputHash::VISION_INPUT_MODE_PII_SAFE_HYBRID, $payload['input_mode'] ?? null);
        $this->assertSame(LaboratoryResultInputHash::VISION_EXTRACTOR_VERSION, $payload['extractor_version'] ?? null);
        $this->assertSame(LaboratoryResultVisionHybridMessageBuilder::SOURCE_TEXT_HEADER, 'SOURCE TEXT EXTRACTED FROM PDF');
        $this->assertGreaterThan(0, $payload['image_count'] ?? 0);
    }

    private function makeVersion(): LaboratoryResultVersion
    {
        $version = new LaboratoryResultVersion;
        $version->id = 99;
        $version->sha256 = hash('sha256', 'shadow-real-pdf-version');
        $version->source = 'gda';

        return $version;
    }
}
