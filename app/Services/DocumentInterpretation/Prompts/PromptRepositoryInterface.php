<?php

namespace App\Services\DocumentInterpretation\Prompts;

interface PromptRepositoryInterface
{
    public function find(string $promptKey): ?PromptDefinition;

    /**
     * @return list<PromptDefinition>
     */
    public function all(): array;
}
