<?php

namespace Tests\Unit\LaboratoryResults;

use App\Models\AiExecution;
use App\Models\AiPrompt;
use App\Models\LaboratoryResultExtractionQaMetric;
use App\Models\LaboratoryResultVersion;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultExtractionComparisonReport;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultExtractionComparisonSummary;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultExtractionQaRecorder;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultInputHash;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultTextExtractor;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionExtractor;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPromptDefinition;
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

class LaboratoryResultVisionIdempotencyTest extends TestCase
{
    use LaboratoryResultVisionHybridTestDoubles;
    private const OPENAI_RESPONSE = [
        'content' => [
            'reported_at' => '2026-01-15',
            'observations' => [
                [
                    'analyte_name_raw' => 'Glucosa',
                    'value' => '95',
                    'value_type' => 'numeric',
                    'unit' => 'mg/dL',
                    'reference_text' => '70-100',
                    'panel_name_raw' => 'Quimica',
                    'source_page' => 1,
                    'confidence' => 0.98,
                ],
                [
                    'analyte_name_raw' => 'Creatinina',
                    'value' => '0.9',
                    'value_type' => 'numeric',
                    'unit' => 'mg/dL',
                    'reference_text' => null,
                    'panel_name_raw' => null,
                    'source_page' => 2,
                    'confidence' => 0.91,
                ],
            ],
        ],
        'model' => 'gpt-4o-mini',
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
    ];

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();

        Config::set('laboratory-results.vision_extraction.enabled', true);
        Config::set('laboratory-results.vision_extraction.shadow_mode', true);

        Schema::dropIfExists('laboratory_result_extraction_qa_metrics');
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

        Schema::create('laboratory_result_extraction_qa_metrics', function ($table) {
            $table->id();
            $table->unsignedBigInteger('laboratory_result_version_id');
            $table->unsignedBigInteger('text_report_id')->nullable();
            $table->unsignedBigInteger('vision_report_id')->nullable();
            $table->string('comparison_outcome', 40);
            $table->unsignedInteger('text_observation_count')->default(0);
            $table->unsignedInteger('vision_observation_count')->default(0);
            $table->unsignedInteger('match_count')->default(0);
            $table->unsignedInteger('conflict_count')->default(0);
            $table->unsignedInteger('vision_only_count')->default(0);
            $table->unsignedInteger('text_only_count')->default(0);
            $table->unsignedInteger('unresolved_count')->default(0);
            $table->decimal('match_rate', 8, 4)->nullable();
            $table->decimal('conflict_rate', 8, 4)->nullable();
            $table->decimal('vision_coverage', 8, 4)->nullable();
            $table->decimal('vision_only_rate', 8, 4)->nullable();
            $table->json('fallback_reasons')->nullable();
            $table->string('text_extraction_status', 30)->nullable();
            $table->string('vision_extraction_status', 30)->nullable();
            $table->decimal('vision_confidence_avg', 5, 4)->nullable();
            $table->unsignedSmallInteger('vision_page_count')->nullable();
            $table->unsignedBigInteger('ai_execution_id')->nullable();
            $table->string('text_extractor_version', 40)->nullable();
            $table->string('vision_extractor_version', 40)->nullable();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->boolean('shadow_mode')->default(true);
            $table->json('summary')->nullable();
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
        Schema::dropIfExists('laboratory_result_extraction_qa_metrics');
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
    public function primera_ejecucion_persiste_observations_en_response_payload(): void
    {
        $openAi = Mockery::mock(OpenAiClient::class);
        $openAi->shouldReceive('chatCompletionWithMetadata')->once()->andReturn(self::OPENAI_RESPONSE);

        $result = $this->makeExtractor($openAi)->extractForVersion(
            $this->makeVersion(),
            LaboratoryResultsPdfFixture::binary(['Glucosa 95 mg/dL 70-100']),
        );

        $payload = $result->aiExecution?->response_payload;

        $this->assertCount(2, $result->candidates);
        $this->assertIsArray($payload['observations'] ?? null);
        $this->assertCount(2, $payload['observations']);
        $this->assertSame(2, $payload['observation_count']);
        $this->assertSame('2026-01-15', $payload['reported_at']);
        $this->assertSame('Glucosa', $payload['observations'][0]['analyte_name_raw']);
        $this->assertSame('95', $payload['observations'][0]['value']);
        $this->assertSame('numeric', $payload['observations'][0]['value_type']);
        $this->assertSame('mg/dL', $payload['observations'][0]['unit']);
        $this->assertSame('70-100', $payload['observations'][0]['reference_text']);
        $this->assertSame('Quimica', $payload['observations'][0]['panel_name_raw']);
        $this->assertSame(1, $payload['observations'][0]['source_page']);
        $this->assertSame(0.98, $payload['observations'][0]['confidence']);
    }

    #[Test]
    public function segunda_ejecucion_reutiliza_observations_sin_llamar_openai(): void
    {
        $openAi = Mockery::mock(OpenAiClient::class);
        $openAi->shouldReceive('chatCompletionWithMetadata')->once()->andReturn(self::OPENAI_RESPONSE);

        $extractor = $this->makeExtractor($openAi);
        $version = $this->makeVersion();
        $pdf = LaboratoryResultsPdfFixture::binary(['Glucosa 95 mg/dL 70-100']);

        $first = $extractor->extractForVersion($version, $pdf);
        $second = $extractor->extractForVersion($version, $pdf);

        $this->assertFalse($first->skippedIdempotent);
        $this->assertTrue($second->skippedIdempotent);
        $this->assertCount(2, $first->candidates);
        $this->assertCount(2, $second->candidates);
        $this->assertSame($first->aiExecution?->id, $second->aiExecution?->id);
        $this->assertSame(
            $first->candidates[0]->numericValue,
            $second->candidates[0]->numericValue,
        );
    }

    #[Test]
    public function input_hash_mantiene_idempotencia(): void
    {
        $openAi = Mockery::mock(OpenAiClient::class);
        $openAi->shouldReceive('chatCompletionWithMetadata')->once()->andReturn(self::OPENAI_RESPONSE);

        $extractor = $this->makeExtractor($openAi);
        $version = $this->makeVersion();
        $pdf = LaboratoryResultsPdfFixture::binary(['Glucosa 95 mg/dL 70-100']);

        $first = $extractor->extractForVersion($version, $pdf);
        $second = $extractor->extractForVersion($version, $pdf);

        $expectedHash = LaboratoryResultInputHash::compute(
            $version->sha256,
            LaboratoryResultInputHash::VISION_EXTRACTOR_VERSION,
            LaboratoryResultVisionPromptDefinition::VERSION_V2,
        );

        $this->assertSame($expectedHash, $first->inputHash);
        $this->assertSame($expectedHash, $second->inputHash);
        $this->assertSame($expectedHash, $first->aiExecution?->input_hash);
    }

    #[Test]
    public function ai_execution_no_contiene_pdf_base64_ni_pii_explicita(): void
    {
        $openAi = Mockery::mock(OpenAiClient::class);
        $openAi->shouldReceive('chatCompletionWithMetadata')->once()->andReturn(self::OPENAI_RESPONSE);

        $result = $this->makeExtractor($openAi)->extractForVersion(
            $this->makeVersion(),
            LaboratoryResultsPdfFixture::binary(['Paciente Juan Perez CURP XXXX Glucosa 95 mg/dL 70-100']),
        );

        $serialized = json_encode([
            $result->aiExecution?->request_payload_redacted,
            $result->aiExecution?->response_payload,
        ]);

        $this->assertStringNotContainsString('base64', strtolower($serialized));
        $this->assertStringNotContainsString('data:image', strtolower($serialized));
        $this->assertStringNotContainsString('curp', strtolower($serialized));
        $this->assertStringNotContainsString('juan perez', strtolower($serialized));
    }

    #[Test]
    public function ejecucion_legacy_sin_observations_se_regenera(): void
    {
        $version = $this->makeVersion();
        $prompt = AiPrompt::query()->firstOrFail();
        $inputHash = LaboratoryResultInputHash::compute(
            $version->sha256,
            (string) config('laboratory-results.vision_extraction.extractor_version', LaboratoryResultInputHash::VISION_EXTRACTOR_VERSION),
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
            'input_hash' => $inputHash,
            'request_payload_redacted' => ['pages_sent' => [1], 'page_count' => 1, 'image_count' => 1],
            'response_payload' => [
                'observation_count' => 2,
                'reported_at' => null,
            ],
            'duration_ms' => 10,
        ]);

        $openAi = Mockery::mock(OpenAiClient::class);
        $openAi->shouldReceive('chatCompletionWithMetadata')->once()->andReturn(self::OPENAI_RESPONSE);

        $result = $this->makeExtractor($openAi)->extractForVersion(
            $version,
            LaboratoryResultsPdfFixture::binary(['Glucosa 95 mg/dL 70-100']),
        );

        $this->assertFalse($result->skippedIdempotent);
        $this->assertCount(2, $result->candidates);
        $this->assertSame('failed', AiExecution::query()->orderBy('id')->first()?->status);
        $this->assertSame('succeeded', $result->aiExecution?->status);
        $this->assertIsArray($result->aiExecution?->response_payload['observations'] ?? null);
    }

    #[Test]
    public function qa_recorder_no_duplica_metrica_en_reutilizacion_idempotente(): void
    {
        $recorder = new LaboratoryResultExtractionQaRecorder;
        $report = new LaboratoryResultExtractionComparisonReport(
            comparison: new LaboratoryResultExtractionComparisonSummary(
                items: [],
                matchCount: 1,
                conflictCount: 0,
                visionOnlyCount: 0,
                textOnlyCount: 0,
                unresolvedCount: 0,
            ),
            textObservationCount: 5,
            visionObservationCount: 2,
            visionExecuted: true,
            shadowMode: true,
        );

        $first = $recorder->record(
            laboratoryResultVersionId: 33,
            report: $report,
            aiExecutionId: 99,
            visionSkippedIdempotent: false,
            visionInputHash: 'abc123',
        );

        $second = $recorder->record(
            laboratoryResultVersionId: 33,
            report: $report,
            aiExecutionId: 99,
            visionSkippedIdempotent: true,
            visionInputHash: 'abc123',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, LaboratoryResultExtractionQaMetric::query()->count());
        $this->assertSame('abc123', $first->summary['vision_input_hash'] ?? null);
    }

    #[Test]
    public function shadow_mode_permanece_activo(): void
    {
        Config::set('laboratory-results.vision_extraction.shadow_mode', true);

        $openAi = Mockery::mock(OpenAiClient::class);
        $openAi->shouldReceive('chatCompletionWithMetadata')->once()->andReturn(self::OPENAI_RESPONSE);

        $result = $this->makeExtractor($openAi)->extractForVersion(
            $this->makeVersion(),
            LaboratoryResultsPdfFixture::binary(['Glucosa 95 mg/dL 70-100']),
        );

        $this->assertTrue($result->success);
        $this->assertTrue((bool) config('laboratory-results.vision_extraction.shadow_mode'));
        $this->assertNotNull($result->aiExecution);
    }

    #[Test]
    public function payload_vacio_observations_es_reutilizable(): void
    {
        $version = $this->makeVersion();
        $prompt = AiPrompt::query()->firstOrFail();
        $inputHash = LaboratoryResultInputHash::compute(
            $version->sha256,
            (string) config('laboratory-results.vision_extraction.extractor_version', LaboratoryResultInputHash::VISION_EXTRACTOR_VERSION),
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
            'input_hash' => $inputHash,
            'request_payload_redacted' => ['pages_sent' => [1], 'page_count' => 1, 'image_count' => 0],
            'response_payload' => [
                'reported_at' => null,
                'observations' => [],
                'observation_count' => 0,
            ],
            'duration_ms' => 5,
        ]);

        $openAi = Mockery::mock(OpenAiClient::class);
        $openAi->shouldNotReceive('chatCompletionWithMetadata');

        $result = $this->makeExtractor($openAi)->extractForVersion(
            $version,
            LaboratoryResultsPdfFixture::binary(['Glucosa 95 mg/dL 70-100']),
        );

        $this->assertTrue($result->skippedIdempotent);
        $this->assertSame([], $result->candidates);
    }

    private function makeExtractor(OpenAiClient $openAi): LaboratoryResultVisionExtractor
    {
        return $this->makeHybridVisionExtractor($openAi);
    }

    private function makeVersion(): LaboratoryResultVersion
    {
        $version = new LaboratoryResultVersion;
        $version->id = 42;
        $version->sha256 = hash('sha256', 'test-pdf-idempotency');
        $version->source = 'gda';

        return $version;
    }
}
