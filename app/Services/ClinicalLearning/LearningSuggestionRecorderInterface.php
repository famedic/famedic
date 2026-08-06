<?php

namespace App\Services\ClinicalLearning;

/**
 * Port for persisting operator corrections used later by AI Learning.
 * Does not call OpenAI and never retrains models.
 */
interface LearningSuggestionRecorderInterface
{
    /**
     * @param  array{
     *   type: string,
     *   detected_text: string,
     *   confirmed_text: string,
     *   confirmed_catalog_id?: string|null,
     *   action?: string,
     *   session_id?: string|null,
     *   meta?: array<string, mixed>|null
     * }  $payload
     */
    public function record(int $userId, array $payload): void;
}
