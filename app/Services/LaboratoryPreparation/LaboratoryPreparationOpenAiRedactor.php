<?php

namespace App\Services\LaboratoryPreparation;

use App\Models\AiExecution;
use App\Models\AiPrompt;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class LaboratoryPreparationOpenAiRedactor
{
    private const DOMAIN = 'laboratory';

    private const FEATURE = 'laboratory_preparation_redactor';

    private const PROMPT_KEY = 'laboratory_preparation_redactor';

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly LaboratoryPreparationRedactionPayloadBuilder $payloadBuilder = new LaboratoryPreparationRedactionPayloadBuilder,
        private readonly LaboratoryPreparationRedactionValidator $validator = new LaboratoryPreparationRedactionValidator,
    ) {}

    public function redact(
        LaboratoryPreparationDecision $decision,
        ?Model $subject = null,
    ): LaboratoryPreparationRedactionResult {
        if ($decision->status === LaboratoryPreparationDecision::STATUS_FALLBACK_ORIGINAL) {
            return LaboratoryPreparationRedactionResult::originalFallback($decision);
        }

        $prompt = $this->activePrompt();
        $payload = $this->payloadBuilder->build($decision);
        $inputHash = $this->inputHash($payload, $prompt->version);
        $model = $prompt->model ?: (string) config('services.openai.model', 'gpt-4o-mini');
        $startedAt = microtime(true);

        try {
            $result = $this->openAiClient->chatCompletionWithMetadata(
                messages: $this->messages($prompt, $payload),
                model: $model,
                jsonSchema: $prompt->response_schema,
                schemaName: self::FEATURE,
                temperature: 0,
            );

            $content = $this->validator->validate($result['content'], $payload);
            $execution = $this->recordExecution(
                prompt: $prompt,
                model: (string) ($result['model'] ?: $model),
                inputHash: $inputHash,
                payload: $payload,
                status: AiExecution::STATUS_SUCCEEDED,
                durationMs: $this->durationMs($startedAt),
                subject: $subject,
                responsePayload: $content,
                usage: $result['usage'],
            );

            return LaboratoryPreparationRedactionResult::aiRedacted($decision, $content, $execution);
        } catch (Throwable $exception) {
            $execution = $this->recordExecution(
                prompt: $prompt,
                model: $model,
                inputHash: $inputHash,
                payload: $payload,
                status: AiExecution::STATUS_FAILED,
                durationMs: $this->durationMs($startedAt),
                subject: $subject,
                error: Str::limit($exception->getMessage(), 2000, ''),
            );

            $technicalDecision = LaboratoryPreparationDecision::fallbackOriginal(
                orderId: $decision->orderId,
                rulesVersion: $decision->rulesVersion,
                rulesApplied: $decision->rulesApplied,
                fallbackReason: 'ai_redaction_failed',
                fallbackCategory: LaboratoryPreparationDecision::FALLBACK_TECHNICAL_AI_FAILURE,
                needsProviderReview: false,
                originalInstructions: $decision->originalInstructions,
            );

            return LaboratoryPreparationRedactionResult::originalFallback(
                decision: $technicalDecision,
                technicalFailureReason: 'ai_redaction_failed',
                aiExecution: $execution,
            );
        }
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
            throw new RuntimeException('No active laboratory preparation redactor prompt is configured.');
        }

        return $prompt;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{role: string, content: string}>
     */
    private function messages(AiPrompt $prompt, array $payload): array
    {
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';

        return [
            ['role' => 'system', 'content' => $prompt->system_prompt],
            ['role' => 'user', 'content' => str_replace('{{redaction_payload_json}}', $payloadJson, $prompt->user_prompt)],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function inputHash(array $payload, int $promptVersion): string
    {
        return hash('sha256', json_encode([
            'prompt_version' => $promptVersion,
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $responsePayload
     * @param  array<string, mixed>  $usage
     */
    private function recordExecution(
        AiPrompt $prompt,
        string $model,
        string $inputHash,
        array $payload,
        string $status,
        int $durationMs,
        ?Model $subject = null,
        ?array $responsePayload = null,
        array $usage = [],
        ?string $error = null,
    ): AiExecution {
        $promptTokens = $this->nullableInt($usage['prompt_tokens'] ?? null);
        $completionTokens = $this->nullableInt($usage['completion_tokens'] ?? null);
        $totalTokens = $this->nullableInt($usage['total_tokens'] ?? null);

        return AiExecution::query()->create([
            'domain' => self::DOMAIN,
            'feature' => self::FEATURE,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'prompt_id' => $prompt->id,
            'prompt_version' => $prompt->version,
            'model' => $model,
            'status' => $status,
            'input_hash' => $inputHash,
            'request_payload_redacted' => [
                'input' => $payload,
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
        ]);
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
