<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Models\AiExecution;
use App\Models\AiPrompt;
use App\Models\LaboratoryResultVersion;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * @deprecated FASE 8C-10D — promovido a {@see LaboratoryResultVisionExtractor}.
 *             Conservado para AiExecution históricos del experimento 10B
 *             (`lab_results_extraction_experiment_10b`) y comando compare-experiment.
 */
class LaboratoryResultVisionExperimentalExtractor
{
    public const DOMAIN = 'lab_results_extraction';

    public const FEATURE = 'lab_results_extraction_experiment_10b';

    public const PROMPT_KEY = 'lab_results_extraction';

    public const EXPERIMENT_KEY = LaboratoryResultInputHash::VISION_EXPERIMENT_10B_KEY;

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly LaboratoryResultTextExtractor $textExtractor,
        private readonly LaboratoryResultVisionPageSelector $pageSelector,
        private readonly LaboratoryResultVisionHybridMessageBuilder $hybridMessageBuilder,
        private readonly LaboratoryResultVisionResponseParser $responseParser,
        private readonly LaboratoryResultVisionRealPdfPageRenderer $realPdfPageRenderer,
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

        if (! $this->realPdfPageRenderer->isAvailable()) {
            return new LaboratoryResultVisionExtractionResult(
                success: false,
                errorCode: 'real_pdf_raster_unavailable',
                errorMessage: 'pdftoppm is not available for real PDF rasterization.',
            );
        }

        $prompt = $this->activePrompt();
        $extractorVersion = LaboratoryResultInputHash::VISION_EXPERIMENT_10B_EXTRACTOR_VERSION;
        $inputHash = LaboratoryResultInputHash::computeExperiment(
            $version->sha256,
            self::EXPERIMENT_KEY,
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

        if ($existingExecution !== null && $this->isExecutionReusable($existingExecution)) {
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

        throw new RuntimeException('Active Vision prompt not found for experiment.');
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
        string $status,
        int $durationMs,
        ?array $responsePayload = null,
        array $usage = [],
        ?string $error = null,
        ?AiExecution $execution = null,
    ): AiExecution {
        $promptTokens = is_numeric($usage['prompt_tokens'] ?? null) ? (int) $usage['prompt_tokens'] : null;
        $completionTokens = is_numeric($usage['completion_tokens'] ?? null) ? (int) $usage['completion_tokens'] : null;
        $totalTokens = is_numeric($usage['total_tokens'] ?? null) ? (int) $usage['total_tokens'] : null;

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
                'experiment_key' => self::EXPERIMENT_KEY,
                'input_mode' => 'real_pdf_raster_hybrid',
                'pages_sent' => $pagesSent,
                'page_count' => count($pagesSent),
                'image_count' => $imageCount,
                'page_diagnostics' => $pageDiagnostics,
                'prompt_key' => $prompt->key,
                'prompt_version' => $prompt->version,
                'extractor_version' => LaboratoryResultInputHash::VISION_EXPERIMENT_10B_EXTRACTOR_VERSION,
            ],
            'response_payload' => $responsePayload,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens ?? (($promptTokens !== null && $completionTokens !== null) ? $promptTokens + $completionTokens : null),
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
}
