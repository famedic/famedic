<?php

namespace App\Services\DocumentInterpretation\Prompts;

final class PromptDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $version,
        public readonly string $status,
        public readonly string $label,
        public readonly string $model,
        public readonly float $temperature,
        public readonly float $topP,
        public readonly int $maxTokens,
        public readonly string $systemPrompt,
        public readonly string $userPrompt,
        public readonly string $sourceFile,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $sourceFile): self
    {
        return new self(
            key: (string) ($data['key'] ?? 'unknown'),
            version: (string) ($data['version'] ?? '0.0.0'),
            status: (string) ($data['status'] ?? 'experimental'),
            label: (string) ($data['label'] ?? 'Prompt'),
            model: (string) ($data['model'] ?? config('clinical_interpreter.openai.model', 'gpt-4o')),
            temperature: (float) ($data['temperature'] ?? 0.1),
            topP: (float) ($data['top_p'] ?? 1.0),
            maxTokens: (int) ($data['max_tokens'] ?? 2500),
            systemPrompt: (string) ($data['system_prompt'] ?? ''),
            userPrompt: (string) ($data['user_prompt'] ?? ''),
            sourceFile: $sourceFile,
        );
    }

    /**
     * Shape consumed by Configuración IA UI.
     *
     * @return array<string, mixed>
     */
    public function toConfigArray(): array
    {
        return [
            'key' => $this->key,
            'version' => $this->version,
            'status' => $this->status,
            'label' => $this->label,
            'model' => $this->model,
            'temperature' => $this->temperature,
            'top_p' => $this->topP,
            'max_tokens' => $this->maxTokens,
            'system_prompt' => $this->systemPrompt,
            'user_prompt' => $this->userPrompt,
            'editable' => false,
            'source' => 'PromptProvider (solo lectura)',
        ];
    }
}
