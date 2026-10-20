<?php

namespace App\Services\LaboratoryResults\AiExplanation;

use App\Enums\LaboratoryResultAiExplanationStatus;
use App\Models\AiExecution;
use App\Models\AiPrompt;
use App\Models\LaboratoryResultAiExplanation;
use App\Models\LaboratoryResultObservation;
use App\Services\LaboratoryResults\AiExplanation\Contract\LaboratoryResultAiExplanationContract;
use App\Services\LaboratoryResults\AiExplanation\Contract\LaboratoryResultAiExplanationInputBuilder;
use App\Services\LaboratoryResults\AiExplanation\Contract\LaboratoryResultAiExplanationOutputValidator;
use App\Services\LaboratoryResults\AiExplanation\Contract\LaboratoryResultAiExplanationValidationException;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class LaboratoryResultAiExplanationGenerator
{
    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly LaboratoryResultAiExplanationInputBuilder $inputBuilder,
        private readonly LaboratoryResultAiExplanationOutputValidator $outputValidator,
    ) {}

    public function generate(LaboratoryResultAiExplanation $explanation): LaboratoryResultAiExplanation
    {
        $explanation->loadMissing('observation.report');
        $observation = $explanation->observation;

        if (! $observation instanceof LaboratoryResultObservation) {
            throw new RuntimeException('AI explanation observation missing.');
        }

        $before = $this->clinicalSnapshot($observation);
        $prompt = $this->activePrompt();
        $input = $this->inputBuilder->fromObservation($observation);
        $model = $prompt->model ?: (string) config('services.openai.model', 'gpt-4o-mini');
        $startedAt = microtime(true);

        $explanation->update(['status' => LaboratoryResultAiExplanationStatus::Generating]);

        $execution = $this->recordExecution(
            observation: $observation,
            prompt: $prompt,
            model: $model,
            inputHash: $explanation->input_hash,
            input: $input,
            status: AiExecution::STATUS_PROCESSING,
            durationMs: 0,
            executionId: $explanation->ai_execution_id,
        );

        $explanation->update(['ai_execution_id' => $execution->id]);

        try {
            $result = $this->openAiClient->chatCompletionWithMetadata(
                messages: $this->messages($prompt, $input),
                model: $model,
                jsonSchema: $prompt->response_schema,
                schemaName: LaboratoryResultAiExplanationContract::FEATURE,
                temperature: 0,
            );

            $validated = $this->outputValidator->validate($result['content'], $input);
            $durationMs = $this->durationMs($startedAt);

            $this->recordExecution(
                observation: $observation,
                prompt: $prompt,
                model: (string) ($result['model'] ?: $model),
                inputHash: $explanation->input_hash,
                input: $input,
                status: AiExecution::STATUS_SUCCEEDED,
                durationMs: $durationMs,
                responsePayload: $validated,
                usage: $result['usage'],
                executionId: $execution->id,
            );

            $explanation->update([
                'status' => LaboratoryResultAiExplanationStatus::Ready,
                'explanation' => $validated['explanation'],
                'limitations' => $validated['limitations'],
                'generated_at' => now(),
            ]);

            $observation->refresh();
            $this->assertObservationUnchanged($before, $this->clinicalSnapshot($observation));

            return $explanation->fresh();
        } catch (LaboratoryResultAiExplanationValidationException $exception) {
            return $this->markInvalid($explanation, $execution, $observation, $before, $exception, $startedAt, $input);
        } catch (Throwable $exception) {
            return $this->markFailed($explanation, $execution, $observation, $before, $exception, $startedAt, $input, $prompt, $model);
        }
    }

    /** @param array<string, mixed> $input @param array<string, mixed>|null $usage */
    private function recordExecution(
        LaboratoryResultObservation $observation,
        AiPrompt $prompt,
        string $model,
        string $inputHash,
        array $input,
        string $status,
        int $durationMs,
        ?array $responsePayload = null,
        ?array $usage = null,
        ?string $error = null,
        ?int $executionId = null,
    ): AiExecution {
        $attributes = [
            'domain' => LaboratoryResultAiExplanationContract::DOMAIN,
            'feature' => LaboratoryResultAiExplanationContract::FEATURE,
            'subject_type' => $observation->getMorphClass(),
            'subject_id' => $observation->id,
            'prompt_id' => $prompt->id,
            'prompt_version' => $prompt->version,
            'model' => $model,
            'status' => $status,
            'input_hash' => $inputHash,
            'request_payload_redacted' => $input,
            'response_payload' => $responsePayload,
            'prompt_tokens' => isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            'completion_tokens' => isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
            'total_tokens' => isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : null,
            'duration_ms' => $durationMs,
            'error' => $error,
        ];

        if ($executionId) {
            AiExecution::query()->whereKey($executionId)->update($attributes);

            return AiExecution::query()->findOrFail($executionId);
        }

        return AiExecution::query()->create($attributes);
    }

    /** @param array<string, mixed> $input */
    private function messages(AiPrompt $prompt, array $input): array
    {
        $inputJson = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $userPrompt = str_replace('{{input_json}}', $inputJson ?: '{}', $prompt->user_prompt);

        return [
            ['role' => 'system', 'content' => $prompt->system_prompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];
    }

    private function activePrompt(): AiPrompt
    {
        $prompt = AiPrompt::query()
            ->where('domain', LaboratoryResultAiExplanationContract::DOMAIN)
            ->where('key', LaboratoryResultAiExplanationContract::PROMPT_KEY)
            ->where('status', AiPrompt::STATUS_ACTIVE)
            ->orderByDesc('version')
            ->first();

        if (! $prompt) {
            throw new RuntimeException('Active AI explanation prompt not found.');
        }

        return $prompt;
    }

    /** @return array<string, mixed> */
    private function clinicalSnapshot(LaboratoryResultObservation $observation): array
    {
        return [
            'reference_status' => $observation->reference_status?->value,
            'abnormal_flag' => $observation->abnormal_flag,
            'numeric_value' => $observation->numeric_value,
            'text_value' => $observation->text_value,
            'unit' => $observation->unit,
            'reference_low' => $observation->reference_low,
            'reference_high' => $observation->reference_high,
        ];
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    private function assertObservationUnchanged(array $before, array $after): void
    {
        if ($before !== $after) {
            throw new RuntimeException('AI explanation flow attempted to modify observation clinical fields.');
        }
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $input */
    private function markInvalid(
        LaboratoryResultAiExplanation $explanation,
        AiExecution $execution,
        LaboratoryResultObservation $observation,
        array $before,
        LaboratoryResultAiExplanationValidationException $exception,
        float $startedAt,
        array $input,
    ): LaboratoryResultAiExplanation {
        $this->recordExecution(
            observation: $observation,
            prompt: $this->activePrompt(),
            model: (string) $execution->model,
            inputHash: $explanation->input_hash,
            input: $input,
            status: AiExecution::STATUS_FAILED,
            durationMs: $this->durationMs($startedAt),
            error: Str::limit($exception->getMessage(), 2000, ''),
            executionId: $execution->id,
        );

        $explanation->update([
            'status' => LaboratoryResultAiExplanationStatus::Invalid,
            'explanation' => null,
            'limitations' => null,
        ]);

        $observation->refresh();
        $this->assertObservationUnchanged($before, $this->clinicalSnapshot($observation));

        return $explanation->fresh();
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $input */
    private function markFailed(
        LaboratoryResultAiExplanation $explanation,
        AiExecution $execution,
        LaboratoryResultObservation $observation,
        array $before,
        Throwable $exception,
        float $startedAt,
        array $input,
        AiPrompt $prompt,
        string $model,
    ): LaboratoryResultAiExplanation {
        $this->recordExecution(
            observation: $observation,
            prompt: $prompt,
            model: $model,
            inputHash: $explanation->input_hash,
            input: $input,
            status: AiExecution::STATUS_FAILED,
            durationMs: $this->durationMs($startedAt),
            error: Str::limit($exception->getMessage(), 2000, ''),
            executionId: $execution->id,
        );

        DB::transaction(function () use ($explanation) {
            $explanation->update([
                'status' => LaboratoryResultAiExplanationStatus::Failed,
                'explanation' => null,
                'limitations' => null,
            ]);
        });

        $observation->refresh();
        $this->assertObservationUnchanged($before, $this->clinicalSnapshot($observation));

        throw $exception;
    }
}
