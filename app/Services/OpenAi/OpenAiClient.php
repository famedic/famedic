<?php

namespace App\Services\OpenAi;

use App\Exceptions\TaxProfiles\ConstanciaExtractionException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiClient
{
    /**
     * Chat Completions with optional Structured Outputs (json_schema).
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>|null  $jsonSchema
     * @return array<string, mixed>
     */
    public function chatCompletion(
        array $messages,
        ?string $model = null,
        ?array $jsonSchema = null,
        ?string $schemaName = null,
        float $temperature = 0,
    ): array {
        $apiKey = config('services.openai.key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        $resolvedModel = $model
            ?: (config('services.openai.tax_profile_model') ?: null)
            ?: config('services.openai.model', 'gpt-4o-mini');

        $payload = [
            'model' => $resolvedModel,
            'temperature' => $temperature,
            'messages' => $messages,
        ];

        if ($jsonSchema !== null) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $schemaName ?: 'structured_response',
                    'strict' => true,
                    'schema' => $jsonSchema,
                ],
            ];
        }

        $timeout = (int) config('services.openai.timeout', 60);

        try {
            $response = Http::timeout($timeout)
                ->withToken($apiKey)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', $payload);
        } catch (ConnectionException $e) {
            throw ConstanciaExtractionException::extractionTimeout();
        }

        if (in_array($response->status(), [408, 504], true)) {
            throw ConstanciaExtractionException::extractionTimeout();
        }

        if (! $response->successful()) {
            throw new RuntimeException('OpenAI request failed with status '.$response->status());
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('OpenAI returned an empty completion.');
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI returned non-JSON structured content.');
        }

        return $decoded;
    }
}
