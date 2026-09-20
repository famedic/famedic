<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Models\AiExecution;
use App\Models\AiPrompt;
use App\Models\LaboratoryResultVersion;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class LaboratoryResultVisionExtractor
{
    public const DOMAIN = 'lab_results_extraction';

    public const FEATURE = 'lab_results_extraction';

    public const PROMPT_KEY = 'lab_results_extraction';

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly LaboratoryResultTextExtractor $textExtractor,
        private readonly LaboratoryResultVisionPageSelector $pageSelector,
        private readonly LaboratoryResultVisionHybridMessageBuilder $hybridMessageBuilder,
        private readonly LaboratoryResultVisionPiiSafePageRenderer $piiSafePageRenderer,
        private readonly LaboratoryResultVisionResponseParser $responseParser,
    ) {}

    public function extractForVersion(
        LaboratoryResultVersion $version,
        string $pdfBinary,
    ): LaboratoryResultVisionExtractionResult {
        if (! config('laboratory-results.vision_extraction.enabled', false)) {
            return new LaboratoryResultVisionExtractionResult(
                success: false,
                errorCode: 'vision_disabled',
                errorMessage: 'Vision extraction is disabled.',
            );
        }

        $prompt = $this->activePrompt();
        $extractorVersion = (string) config(
            'laboratory-results.vision_extraction.extractor_version',
            LaboratoryResultInputHash::VISION_EXTRACTOR_VERSION
        );
        $inputHash = LaboratoryResultInputHash::compute(
            $version->sha256,
            $extractorVersion,
            $prompt->version,
        );

        $existingExecution = AiExecution::query()
            ->where('domain', self::DOMAIN)
            ->where('feature', self::FEATURE)
            ->where('subject_type', $version->getMorphClass())
            ->where('subject_id', $version->id)
            ->where('input_hash', $inputHash)
            ->where('status', AiExecution::STATUS_SUCCEEDED)
            ->latest('id')
            ->first();

        if ($existingExecution !== null) {
            if ($this->isExecutionReusable($existingExecution)) {
                return new LaboratoryResultVisionExtractionResult(
                    success: true,
                    candidates: $this->candidatesFromExecution($existingExecution),
                    pagesSent: (array) ($existingExecution->request_payload_redacted['pages_sent'] ?? []),
                    aiExecution: $existingExecution,
                    durationMs: (int) ($existingExecution->duration_ms ?? 0),
                    promptVersion: $prompt->version,
                    extractorVersion: $extractorVersion,
                    inputHash: $inputHash,
                    skippedIdempotent: true,
                );
            }

            $this->invalidateIncompleteExecution($existingExecution);
        }

        $textExtraction = $this->textExtractor->extractFromBinary($pdfBinary);

        if (! $textExtraction->success) {
            return new LaboratoryResultVisionExtractionResult(
                success: false,
                errorCode: 'pdf_extract_failed',
                errorMessage: $textExtraction->errorMessage,
                promptVersion: $prompt->version,
                extractorVersion: $extractorVersion,
                inputHash: $inputHash,
            );
        }

        $selectedPages = $this->pageSelector->select($textExtraction);

        if ($selectedPages === []) {
            return new LaboratoryResultVisionExtractionResult(
                success: false,
                errorCode: 'no_pages_selected',
                errorMessage: 'No pages available for vision extraction.',
                promptVersion: $prompt->version,
                extractorVersion: $extractorVersion,
                inputHash: $inputHash,
            );
        }

        if (! $this->piiSafePageRenderer->isAvailable()) {
            return new LaboratoryResultVisionExtractionResult(
                success: false,
                errorCode: 'pii_safe_renderer_unavailable',
                errorMessage: 'PII-safe PDF rasterization is not available (pdftoppm, pdftotext, or GD required).',
                promptVersion: $prompt->version,
                extractorVersion: $extractorVersion,
                inputHash: $inputHash,
            );
        }

        $model = $prompt->model ?: (string) config('services.openai.model', 'gpt-4o-mini');
        [$messages, $imageCount, $pageDiagnostics] = $this->hybridMessageBuilder->build(
            $prompt,
            $pdfBinary,
            $selectedPages,
        );
        $pageNumbers = array_map(fn (array $page): int => (int) $page['page'], $selectedPages);
        $startedAt = microtime(true);

        $execution = $this->recordExecution(
            version: $version,
            prompt: $prompt,
            model: $model,
            inputHash: $inputHash,
            pagesSent: $pageNumbers,
            imageCount: $imageCount,
            pageDiagnostics: $pageDiagnostics,
            extractorVersion: $extractorVersion,
            status: AiExecution::STATUS_PROCESSING,
            durationMs: 0,
        );

        try {
            $result = $this->openAiClient->chatCompletionWithMetadata(
                messages: $messages,
                model: $model,
                jsonSchema: $prompt->response_schema,
                schemaName: self::FEATURE,
                temperature: 0,
                timeoutSeconds: (int) config('laboratory-results.vision_extraction.timeout', 90),
            );

            $durationMs = $this->durationMs($startedAt);
            $candidates = $this->responseParser->parse($result['content']);

            $execution = $this->recordExecution(
                version: $version,
                prompt: $prompt,
                model: (string) ($result['model'] ?: $model),
                inputHash: $inputHash,
                pagesSent: $pageNumbers,
                imageCount: $imageCount,
                pageDiagnostics: $pageDiagnostics,
                extractorVersion: $extractorVersion,
                status: AiExecution::STATUS_SUCCEEDED,
                durationMs: $durationMs,
                responsePayload: $this->buildPersistedResponsePayload($result['content']),
                usage: $result['usage'],
                execution: $execution,
            );

            return new LaboratoryResultVisionExtractionResult(
                success: true,
                candidates: $candidates,
                pagesSent: $pageNumbers,
                aiExecution: $execution,
                durationMs: $durationMs,
                promptVersion: $prompt->version,
                extractorVersion: $extractorVersion,
                inputHash: $inputHash,
            );
        } catch (InvalidArgumentException $e) {
            return $this->finalizeFailure(
                version: $version,
                prompt: $prompt,
                model: $model,
                inputHash: $inputHash,
                pagesSent: $pageNumbers,
                pageDiagnostics: $pageDiagnostics,
                startedAt: $startedAt,
                execution: $execution,
                errorCode: 'invalid_vision_json',
                errorMessage: $e->getMessage(),
                extractorVersion: $extractorVersion,
            );
        } catch (Throwable $e) {
            $errorCode = Str::contains(mb_strtolower($e->getMessage()), 'timeout')
                ? 'vision_timeout'
                : 'vision_api_error';

            return $this->finalizeFailure(
                version: $version,
                prompt: $prompt,
                model: $model,
                inputHash: $inputHash,
                pagesSent: $pageNumbers,
                pageDiagnostics: $pageDiagnostics,
                startedAt: $startedAt,
                execution: $execution,
                errorCode: $errorCode,
                errorMessage: $e->getMessage(),
                extractorVersion: $extractorVersion,
            );
        }
    }

    private function activePrompt(): AiPrompt
    {
        $prompt = AiPrompt::query()
            ->where('key', self::PROMPT_KEY)
            ->where('status', AiPrompt::STATUS_ACTIVE)
            ->orderByDesc('version')
            ->first();

        if ($prompt) {
            return $prompt;
        }

        $record = LaboratoryResultVisionPromptDefinition::record(
            LaboratoryResultVisionPromptDefinition::VERSION_V3
        );

        return AiPrompt::query()->create([
            'key' => $record['key'],
            'domain' => $record['domain'],
            'version' => $record['version'],
            'status' => AiPrompt::STATUS_ACTIVE,
            'model' => $record['model'],
            'system_prompt' => $record['system_prompt'],
            'user_prompt' => $record['user_prompt'],
            'response_schema' => $record['response_schema'],
        ]);
    }

    /**
     * @param  list<int>  $pagesSent
     * @param  list<array{page: int, image_source: string, has_source_text: bool}>  $pageDiagnostics
     * @param  array<string, mixed>  $usage
     */
    private function recordExecution(
        LaboratoryResultVersion $version,
        AiPrompt $prompt,
        string $model,
        string $inputHash,
        array $pagesSent,
        int $imageCount,
        array $pageDiagnostics,
        string $extractorVersion,
        string $status,
        int $durationMs,
        ?array $responsePayload = null,
        array $usage = [],
        ?string $error = null,
        ?AiExecution $execution = null,
    ): AiExecution {
        $promptTokens = $this->nullableInt($usage['prompt_tokens'] ?? null);
        $completionTokens = $this->nullableInt($usage['completion_tokens'] ?? null);
        $totalTokens = $this->nullableInt($usage['total_tokens'] ?? null);

        $attributes = [
            'domain' => self::DOMAIN,
            'feature' => self::FEATURE,
            'subject_type' => $version->getMorphClass(),
            'subject_id' => $version->id,
            'prompt_id' => $prompt->id,
            'prompt_version' => $prompt->version,
            'model' => $model,
            'status' => $status,
            'input_hash' => $inputHash,
            'request_payload_redacted' => [
                'input_mode' => LaboratoryResultInputHash::VISION_INPUT_MODE_PII_SAFE_HYBRID,
                'pages_sent' => $pagesSent,
                'page_count' => count($pagesSent),
                'image_count' => $imageCount,
                'page_diagnostics' => $pageDiagnostics,
                'prompt_key' => $prompt->key,
                'prompt_version' => $prompt->version,
                'extractor_version' => $extractorVersion,
            ],
            'response_payload' => $responsePayload,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens ?? (($promptTokens !== null && $completionTokens !== null) ? $promptTokens + $completionTokens : null),
            'estimated_cost_usd' => $this->estimateCostUsd($model, $promptTokens ?? 0, $completionTokens ?? 0),
            'duration_ms' => $durationMs,
            'error' => $error,
        ];

        if ($execution) {
            $execution->update($attributes);

            return $execution->refresh();
        }

        return AiExecution::query()->create($attributes);
    }

    /**
     * @param  list<int>  $pagesSent
     * @param  list<array{page: int, image_source: string, has_source_text: bool}>  $pageDiagnostics
     */
    private function finalizeFailure(
        LaboratoryResultVersion $version,
        AiPrompt $prompt,
        string $model,
        string $inputHash,
        array $pagesSent,
        array $pageDiagnostics,
        float $startedAt,
        AiExecution $execution,
        string $errorCode,
        string $errorMessage,
        string $extractorVersion,
    ): LaboratoryResultVisionExtractionResult {
        $durationMs = $this->durationMs($startedAt);

        $execution = $this->recordExecution(
            version: $version,
            prompt: $prompt,
            model: $model,
            inputHash: $inputHash,
            pagesSent: $pagesSent,
            imageCount: count($pagesSent),
            pageDiagnostics: $pageDiagnostics,
            extractorVersion: $extractorVersion,
            status: AiExecution::STATUS_FAILED,
            durationMs: $durationMs,
            responsePayload: ['error_code' => $errorCode],
            error: $errorMessage,
            execution: $execution,
        );

        return new LaboratoryResultVisionExtractionResult(
            success: false,
            pagesSent: $pagesSent,
            aiExecution: $execution,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            durationMs: $durationMs,
            promptVersion: $prompt->version,
            extractorVersion: $extractorVersion,
            inputHash: $inputHash,
        );
    }

    /**
     * @return list<LaboratoryResultObservationCandidate>
     */
    private function candidatesFromExecution(AiExecution $execution): array
    {
        $payload = $execution->response_payload;

        if (! is_array($payload) || ! array_key_exists('observations', $payload) || ! is_array($payload['observations'])) {
            throw new InvalidArgumentException('AiExecution response_payload is missing reusable observations.');
        }

        return $this->responseParser->parse([
            'reported_at' => $payload['reported_at'] ?? null,
            'observations' => $payload['observations'],
        ]);
    }

    private function isExecutionReusable(AiExecution $execution): bool
    {
        if ($execution->status !== AiExecution::STATUS_SUCCEEDED) {
            return false;
        }

        $payload = $execution->response_payload;

        return is_array($payload)
            && array_key_exists('observations', $payload)
            && is_array($payload['observations']);
    }

    private function invalidateIncompleteExecution(AiExecution $execution): void
    {
        $payload = is_array($execution->response_payload) ? $execution->response_payload : [];

        $execution->update([
            'status' => AiExecution::STATUS_FAILED,
            'error' => 'incomplete_response_payload: observations missing; eligible for regeneration',
            'response_payload' => array_merge($payload, [
                'regeneration_reason' => 'missing_observations',
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array{reported_at: ?string, observations: list<array<string, mixed>>, observation_count: int}
     */
    private function buildPersistedResponsePayload(array $content): array
    {
        $observations = [];

        foreach ($content['observations'] ?? [] as $observation) {
            if (! is_array($observation)) {
                continue;
            }

            $observations[] = [
                'analyte_name_raw' => trim((string) ($observation['analyte_name_raw'] ?? '')),
                'value' => $this->nullablePersistedString($observation['value'] ?? null),
                'value_type' => trim((string) ($observation['value_type'] ?? 'text')),
                'unit' => $this->nullablePersistedString($observation['unit'] ?? null),
                'reference_text' => $this->nullablePersistedString($observation['reference_text'] ?? null),
                'panel_name_raw' => $this->nullablePersistedString($observation['panel_name_raw'] ?? null),
                'source_page' => is_numeric($observation['source_page'] ?? null)
                    ? (int) $observation['source_page']
                    : null,
                'confidence' => is_numeric($observation['confidence'] ?? null)
                    ? max(0.0, min(1.0, (float) $observation['confidence']))
                    : 0.5,
            ];
        }

        $reportedAt = $content['reported_at'] ?? null;

        return [
            'reported_at' => is_string($reportedAt) && trim($reportedAt) !== '' ? trim($reportedAt) : null,
            'observations' => $observations,
            'observation_count' => count($observations),
        ];
    }

    private function nullablePersistedString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return null;
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function estimateCostUsd(string $model, int $promptTokens, int $completionTokens): ?float
    {
        if ($promptTokens <= 0 && $completionTokens <= 0) {
            return null;
        }

        $pricing = config('clinical_interpreter.pricing', []);
        $rates = $pricing[$model] ?? $pricing['default'] ?? ['input' => 2.5, 'output' => 10.0];

        return round((((float) $rates['input']) * ($promptTokens / 1_000_000)) + (((float) $rates['output']) * ($completionTokens / 1_000_000)), 6);
    }
}
