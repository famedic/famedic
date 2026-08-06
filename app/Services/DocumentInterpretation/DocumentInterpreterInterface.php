<?php

namespace App\Services\DocumentInterpretation;

use App\Services\DocumentInterpretation\Prompts\PromptDefinition;

/**
 * Port for document interpretation providers (OpenAI Vision, Google, Azure, Textract…).
 * Implementations MUST NOT access Famedic catalogs or take matching decisions.
 */
interface DocumentInterpreterInterface
{
    /**
     * @param  array{contents: string, mime: string, filename: string}  $document
     * @return array{
     *   contract: array<string, mixed>,
     *   raw_content: string,
     *   metrics: array<string, mixed>,
     *   prompt: PromptDefinition
     * }
     */
    public function interpret(array $document, ?PromptDefinition $prompt = null): array;
}
