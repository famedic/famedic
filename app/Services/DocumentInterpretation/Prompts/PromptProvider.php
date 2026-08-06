<?php

namespace App\Services\DocumentInterpretation\Prompts;

/**
 * Loads the active clinical interpretation prompt for Vision providers and Config UI.
 */
class PromptProvider
{
    public function __construct(
        private PromptRepositoryInterface $repository,
    ) {}

    public function active(): PromptDefinition
    {
        $key = (string) config('clinical_interpreter.active_prompt', 'prescription_v1');
        $prompt = $this->repository->find($key);

        if (! $prompt) {
            throw new \RuntimeException("Prompt clínico '{$key}' no encontrado en el repositorio.");
        }

        return $prompt;
    }

    public function get(string $promptKey): PromptDefinition
    {
        $prompt = $this->repository->find($promptKey);

        if (! $prompt) {
            throw new \RuntimeException("Prompt clínico '{$promptKey}' no encontrado.");
        }

        return $prompt;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function catalogForUi(): array
    {
        return array_map(
            fn (PromptDefinition $p) => $p->toConfigArray(),
            $this->repository->all()
        );
    }
}
