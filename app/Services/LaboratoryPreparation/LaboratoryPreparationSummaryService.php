<?php

namespace App\Services\LaboratoryPreparation;

use App\Models\AiExecution;
use App\Models\AiPrompt;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchasePreparationSummary;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class LaboratoryPreparationSummaryService
{
    private const DOMAIN = 'laboratory';

    private const FEATURE = 'laboratory_preparation_summary';

    private const PROMPT_KEY = 'laboratory_preparation_summary';

    /**
     * @var list<string>
     */
    private const GENERIC_SUMMARY_PATTERNS = [
        '/a continuaci[oó]n se present/i',
        '/indicaciones de preparaci[oó]n para los estudios/i',
        '/se presentan las indicaciones/i',
        '/estudios de laboratorio solicitados/i',
    ];

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly LaboratoryPreparationSource $source,
    ) {}

    public function queueExecution(LaboratoryPurchase $purchase): ?AiExecution
    {
        $purchase->loadMissing('laboratoryPurchaseItems');

        $input = $this->buildSourceInput($purchase);
        $sourceHash = $this->sourceHash($input);

        $existing = $purchase->preparationSummary()->first();
        if (
            $existing
            && $existing->source_hash === $sourceHash
            && $existing->status === LaboratoryPurchasePreparationSummary::STATUS_GENERATED
            && $existing->invalidated_at === null
        ) {
            return null;
        }

        $existingExecution = AiExecution::query()
            ->where('domain', self::DOMAIN)
            ->where('feature', self::FEATURE)
            ->where('subject_type', $purchase->getMorphClass())
            ->where('subject_id', $purchase->id)
            ->where('input_hash', $sourceHash)
            ->whereIn('status', [
                AiExecution::STATUS_QUEUED,
                AiExecution::STATUS_PROCESSING,
            ])
            ->latest('id')
            ->first();

        if ($existingExecution) {
            return $existingExecution;
        }

        $prompt = $this->activePrompt();
        $model = $prompt->model ?: (string) config('services.openai.model', 'gpt-4o-mini');

        return $this->recordExecution(
            purchase: $purchase,
            prompt: $prompt,
            model: $model,
            sourceHash: $sourceHash,
            input: $input,
            status: AiExecution::STATUS_QUEUED,
            durationMs: 0,
        );
    }

    public function generate(
        LaboratoryPurchase $purchase,
        ?AiExecution $execution = null,
        bool $throwOnFailure = false,
    ): ?LaboratoryPurchasePreparationSummary {
        $purchase->loadMissing('laboratoryPurchaseItems');

        $input = $this->buildSourceInput($purchase);
        $sourceHash = $this->sourceHash($input);

        $existing = $purchase->preparationSummary()->first();
        if (
            $existing
            && $existing->source_hash === $sourceHash
            && $existing->status === LaboratoryPurchasePreparationSummary::STATUS_GENERATED
            && $existing->invalidated_at === null
        ) {
            return $existing;
        }

        if ($existing && $existing->source_hash !== $sourceHash && $existing->invalidated_at === null) {
            $existing->update([
                'status' => LaboratoryPurchasePreparationSummary::STATUS_STALE,
                'invalidated_at' => now(),
            ]);
        }

        $prompt = $this->activePrompt();
        $messages = $this->messages($prompt, $input);
        $model = $prompt->model ?: (string) config('services.openai.model', 'gpt-4o-mini');
        $startedAt = microtime(true);

        if ($execution && $execution->status !== AiExecution::STATUS_PROCESSING) {
            $execution->update([
                'status' => AiExecution::STATUS_PROCESSING,
                'duration_ms' => 0,
                'error' => null,
            ]);
        }

        try {
            $result = $this->openAiClient->chatCompletionWithMetadata(
                messages: $messages,
                model: $model,
                jsonSchema: $prompt->response_schema,
                schemaName: self::FEATURE,
                temperature: 0,
            );

            $durationMs = $this->durationMs($startedAt);
            $content = $this->validateResponse($result['content'], $input);
            $usage = $result['usage'];
            $execution = $this->recordExecution(
                purchase: $purchase,
                prompt: $prompt,
                model: (string) ($result['model'] ?: $model),
                sourceHash: $sourceHash,
                input: $input,
                status: AiExecution::STATUS_SUCCEEDED,
                durationMs: $durationMs,
                responsePayload: $content,
                usage: $usage,
                execution: $execution,
            );

            return DB::transaction(function () use ($purchase, $sourceHash, $content, $execution) {
                return LaboratoryPurchasePreparationSummary::query()->updateOrCreate(
                    ['laboratory_purchase_id' => $purchase->id],
                    [
                        'ai_execution_id' => $execution->id,
                        'source_hash' => $sourceHash,
                        'status' => LaboratoryPurchasePreparationSummary::STATUS_GENERATED,
                        'summary_text' => $content['summary'],
                        'summary_json' => $content,
                        'generated_at' => now(),
                        'invalidated_at' => null,
                    ]
                );
            });
        } catch (Throwable $exception) {
            $failedResponsePayload = $exception instanceof LaboratoryPreparationFidelityValidationException
                ? $exception->responsePayload
                : null;

            $this->recordExecution(
                purchase: $purchase,
                prompt: $prompt,
                model: $model,
                sourceHash: $sourceHash,
                input: $input,
                status: AiExecution::STATUS_FAILED,
                durationMs: $this->durationMs($startedAt),
                responsePayload: $failedResponsePayload,
                error: Str::limit($exception->getMessage(), 2000, ''),
                execution: $execution,
            );

            if (! $throwOnFailure) {
                report($exception);
            }

            if ($throwOnFailure) {
                throw $exception;
            }

            return null;
        }
    }

    public function generateFallbackFromSource(
        LaboratoryPurchase $purchase,
        ?AiExecution $execution = null,
    ): ?LaboratoryPurchasePreparationSummary {
        $purchase->loadMissing('laboratoryPurchaseItems');

        $input = $this->buildSourceInput($purchase);
        $sourceHash = $this->sourceHash($input);
        $itemsWithIndications = collect($input['items'] ?? [])
            ->filter(fn (array $item) => filled($item['indications'] ?? null))
            ->values();

        if ($itemsWithIndications->isEmpty()) {
            return null;
        }

        $existing = $purchase->preparationSummary()->first();
        if ($existing && $existing->source_hash !== $sourceHash && $existing->invalidated_at === null) {
            $existing->update([
                'status' => LaboratoryPurchasePreparationSummary::STATUS_STALE,
                'invalidated_at' => now(),
            ]);
        }

        $summaryText = $itemsWithIndications->count() === 1
            ? 'Revisa la indicación de preparación disponible para el estudio solicitado.'
            : 'Revisa las indicaciones de preparación disponibles para los estudios solicitados.';

        $content = [
            'summary' => $summaryText,
            'sections' => $itemsWithIndications
                ->map(fn (array $item) => [
                    'key' => 'source_indications_'.$item['id'],
                    'title' => (string) ($item['name'] ?: 'Indicaciones de preparación'),
                    'content' => trim((string) $item['indications']),
                    'source_item_ids' => [(int) $item['id']],
                ])
                ->all(),
            'special_instructions' => [],
            'individual_instructions' => [],
            'fallback' => true,
            'fallback_reason' => 'ai_fidelity_validation_failed',
        ];

        return DB::transaction(function () use ($purchase, $sourceHash, $content, $execution) {
            return LaboratoryPurchasePreparationSummary::query()->updateOrCreate(
                ['laboratory_purchase_id' => $purchase->id],
                [
                    'ai_execution_id' => $execution?->id,
                    'source_hash' => $sourceHash,
                    'status' => LaboratoryPurchasePreparationSummary::STATUS_GENERATED,
                    'summary_text' => $content['summary'],
                    'summary_json' => $content,
                    'generated_at' => now(),
                    'invalidated_at' => null,
                ]
            );
        });
    }

    /**
     * @return array{items: list<array{id: int, name: string, gda_id: string|null, indications: string|null, feature_list: list<string>}>}
     */
    public function buildSourceInput(LaboratoryPurchase $purchase): array
    {
        return $this->source->buildInput($purchase);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function sourceHash(array $input): string
    {
        return $this->source->hash($input);
    }

    public function activePrompt(): AiPrompt
    {
        $prompt = AiPrompt::query()
            ->where('domain', self::DOMAIN)
            ->where('key', self::PROMPT_KEY)
            ->where('status', AiPrompt::STATUS_ACTIVE)
            ->orderByDesc('version')
            ->first();

        if (! $prompt) {
            throw new RuntimeException('No active laboratory preparation AI prompt is configured.');
        }

        return $prompt;
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function messages(AiPrompt $prompt, array $input): array
    {
        $itemsJson = json_encode($input['items'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '[]';

        return [
            ['role' => 'system', 'content' => $prompt->system_prompt],
            ['role' => 'user', 'content' => str_replace('{{items_json}}', $itemsJson, $prompt->user_prompt)],
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validateResponse(array $response, array $input): array
    {
        foreach (['summary', 'sections', 'special_instructions', 'individual_instructions'] as $key) {
            if (! array_key_exists($key, $response)) {
                throw new RuntimeException("OpenAI response is missing [{$key}].");
            }
        }

        if (! is_string($response['summary']) || trim($response['summary']) === '') {
            throw new RuntimeException('OpenAI response summary is empty.');
        }

        $sourceIds = collect($input['items'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $assertIds = function (array $ids) use ($sourceIds): void {
            if ($ids === []) {
                throw new RuntimeException('OpenAI response contains an empty source_item_ids list.');
            }

            foreach ($ids as $id) {
                if (! in_array((int) $id, $sourceIds, true)) {
                    throw new RuntimeException('OpenAI response references an unknown source item id.');
                }
            }
        };

        foreach (Arr::wrap($response['sections']) as $section) {
            if (! is_array($section)) {
                throw new RuntimeException('OpenAI response contains an invalid section.');
            }
            $assertIds(Arr::wrap($section['source_item_ids'] ?? []));
            $this->assertSectionContentQuality($section, $input);
        }

        foreach (Arr::wrap($response['special_instructions']) as $instruction) {
            if (! is_array($instruction)) {
                throw new RuntimeException('OpenAI response contains an invalid special instruction.');
            }
            $assertIds(Arr::wrap($instruction['source_item_ids'] ?? []));
        }

        foreach (Arr::wrap($response['individual_instructions']) as $instruction) {
            if (! is_array($instruction) || ! in_array((int) ($instruction['source_item_id'] ?? 0), $sourceIds, true)) {
                throw new RuntimeException('OpenAI response contains an invalid individual instruction.');
            }
        }

        $this->assertIndicatedItemsAreCovered($response, $input);

        app(LaboratoryPreparationResponseFidelityValidator::class)->validate($response, $input);

        return [
            'summary' => trim($response['summary']),
            'sections' => array_values(Arr::wrap($response['sections'])),
            'special_instructions' => array_values(Arr::wrap($response['special_instructions'])),
            'individual_instructions' => array_values(Arr::wrap($response['individual_instructions'])),
        ];
    }

    private function assertSummaryIsInformative(string $summary): void
    {
        foreach (self::GENERIC_SUMMARY_PATTERNS as $pattern) {
            if (preg_match($pattern, $summary)) {
                throw new RuntimeException('OpenAI response summary is too generic.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $input
     */
    private function assertIndicatedItemsAreCovered(array $response, array $input): void
    {
        $requiredIds = collect($input['items'] ?? [])
            ->filter(fn (array $item) => filled($item['indications'] ?? null))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($requiredIds === []) {
            return;
        }

        $coveredIds = collect(Arr::wrap($response['sections'] ?? []))
            ->flatMap(fn (array $section) => Arr::wrap($section['source_item_ids'] ?? []))
            ->merge(collect(Arr::wrap($response['special_instructions'] ?? []))
                ->flatMap(fn (array $instruction) => Arr::wrap($instruction['source_item_ids'] ?? [])))
            ->merge(collect(Arr::wrap($response['individual_instructions'] ?? []))
                ->map(fn (array $instruction) => (int) ($instruction['source_item_id'] ?? 0)))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        foreach ($requiredIds as $requiredId) {
            if (! in_array($requiredId, $coveredIds, true)) {
                throw new RuntimeException('OpenAI response does not cover all source items with indications.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $input
     */
    private function assertSectionContentQuality(array $section, array $input): void
    {
        $ids = collect(Arr::wrap($section['source_item_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();

        if (count($ids) < 2) {
            return;
        }

        $originals = collect($input['items'] ?? [])
            ->whereIn('id', $ids)
            ->pluck('indications')
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => trim((string) $value))
            ->values()
            ->all();

        if (count($originals) < 2) {
            return;
        }

        $content = trim((string) ($section['content'] ?? ''));
        $uniqueOriginals = array_values(array_unique($originals));

        if (count($uniqueOriginals) === 1) {
            $phrase = $this->normalizeComparisonText($uniqueOriginals[0]);
            if ($phrase !== '' && substr_count($this->normalizeComparisonText($content), $phrase) > 1) {
                throw new RuntimeException('OpenAI response section repeats identical instructions without consolidation.');
            }

            return;
        }

        $normalizedContent = $this->normalizeComparisonText($content);
        $verbatimMatches = 0;

        foreach ($originals as $original) {
            $normalizedOriginal = $this->normalizeComparisonText($original);
            if ($normalizedOriginal !== '' && str_contains($normalizedContent, $normalizedOriginal)) {
                $verbatimMatches++;
            }
        }

        if ($verbatimMatches === count($originals)) {
            throw new RuntimeException('OpenAI response section appears to concatenate original instructions without synthesis.');
        }
    }

    private function normalizeComparisonText(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $responsePayload
     * @param  array<string, mixed>  $usage
     */
    private function recordExecution(
        LaboratoryPurchase $purchase,
        AiPrompt $prompt,
        string $model,
        string $sourceHash,
        array $input,
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
            'subject_type' => $purchase->getMorphClass(),
            'subject_id' => $purchase->id,
            'prompt_id' => $prompt->id,
            'prompt_version' => $prompt->version,
            'model' => $model,
            'status' => $status,
            'input_hash' => $sourceHash,
            'request_payload_redacted' => [
                'input' => $input,
                'prompt_key' => $prompt->key,
                'prompt_version' => $prompt->version,
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
