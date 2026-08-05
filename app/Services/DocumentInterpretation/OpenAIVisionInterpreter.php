<?php

namespace App\Services\DocumentInterpretation;

use App\Services\DocumentInterpretation\Exceptions\InterpretationFailedException;
use App\Services\DocumentInterpretation\Exceptions\InvalidInterpretationJsonException;
use App\Services\DocumentInterpretation\Prompts\PromptDefinition;
use App\Services\DocumentInterpretation\Prompts\PromptProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * OpenAI Vision provider. Interprets only — never matches catalog or suggests products.
 */
class OpenAIVisionInterpreter implements DocumentInterpreterInterface
{
    public function __construct(
        private PromptProvider $prompts,
        private InterpretationMetricsLogger $metricsLogger,
    ) {}

    public function interpret(array $document, ?PromptDefinition $prompt = null): array
    {
        $prompt ??= $this->prompts->active();
        $apiKey = config('services.openai.key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new InterpretationFailedException(
                'La API de OpenAI no está configurada. Agrega OPENAI_API_KEY en el entorno.'
            );
        }

        $mime = $document['mime'] ?? 'image/jpeg';
        $contents = $document['contents'] ?? '';

        if ($contents === '') {
            throw new InterpretationFailedException('El documento está vacío.');
        }

        $model = $prompt->model ?: (string) config('clinical_interpreter.openai.model', 'gpt-4o');
        $base64 = base64_encode($contents);
        $started = microtime(true);

        $response = Http::timeout((int) config('clinical_interpreter.openai.timeout', 90))
            ->withToken($apiKey)
            ->acceptJson()
            ->post((string) config('clinical_interpreter.openai.endpoint'), [
                'model' => $model,
                'temperature' => $prompt->temperature,
                'top_p' => $prompt->topP,
                'max_tokens' => $prompt->maxTokens,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $prompt->systemPrompt,
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => $prompt->userPrompt,
                            ],
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => "data:{$mime};base64,{$base64}",
                                    'detail' => 'high',
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        if (! $response->successful()) {
            $error = $response->json('error.message') ?? $response->body();
            $this->metricsLogger->logFailure([
                'model' => $model,
                'prompt_version' => $prompt->version,
                'duration_ms' => $durationMs,
                'http_status' => $response->status(),
                'error_class' => 'http_error',
            ]);

            throw new InterpretationFailedException(
                'No fue posible interpretar la receta. '.Str::limit((string) $error, 180)
            );
        }

        $rawContent = $response->json('choices.0.message.content');
        if (! is_string($rawContent) || trim($rawContent) === '') {
            $this->metricsLogger->logFailure([
                'model' => $model,
                'prompt_version' => $prompt->version,
                'duration_ms' => $durationMs,
                'error_class' => 'empty_content',
            ]);

            throw new InterpretationFailedException('No fue posible interpretar la receta.');
        }

        $contract = $this->decodeStrictJson($rawContent);
        $usage = $response->json('usage') ?? [];
        $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? 0);
        $estimatedCost = $this->estimateCostUsd($model, $promptTokens, $completionTokens);

        $metrics = [
            'duration_ms' => $durationMs,
            'model' => $model,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
            'estimated_cost_usd' => $estimatedCost,
            'prompt_version' => $prompt->version,
            'prompt_key' => $prompt->key,
            'prompt_status' => $prompt->status,
            'provider' => 'openai_vision',
        ];

        $this->metricsLogger->logSuccess($metrics);

        return [
            'contract' => $contract,
            'raw_content' => $rawContent,
            'metrics' => $metrics,
            'prompt' => $prompt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeStrictJson(string $raw): array
    {
        $trimmed = trim($raw);

        // Strip accidental fences if the model ignores instructions
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed) ?? $trimmed;
            $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
            $trimmed = trim($trimmed);
        }

        $decoded = json_decode($trimmed, true);

        if (! is_array($decoded)) {
            throw new InvalidInterpretationJsonException(
                'Error técnico: la respuesta de Vision no es un JSON válido.'
            );
        }

        foreach (['patient', 'doctor', 'diagnosis', 'laboratory_tests', 'medications', 'instructions', 'observations', 'warnings', 'confidence'] as $key) {
            if (! array_key_exists($key, $decoded)) {
                $decoded[$key] = in_array($key, ['laboratory_tests', 'medications', 'instructions', 'observations', 'warnings'], true)
                    ? []
                    : null;
            }
        }

        if (! array_key_exists('date', $decoded)) {
            $decoded['date'] = null;
        }

        return $decoded;
    }

    private function estimateCostUsd(string $model, int $promptTokens, int $completionTokens): float
    {
        $pricing = config('clinical_interpreter.pricing', []);
        $rates = $pricing[$model] ?? $pricing['default'] ?? ['input' => 2.5, 'output' => 10.0];

        $input = ((float) $rates['input']) * ($promptTokens / 1_000_000);
        $output = ((float) $rates['output']) * ($completionTokens / 1_000_000);

        return round($input + $output, 6);
    }
}
