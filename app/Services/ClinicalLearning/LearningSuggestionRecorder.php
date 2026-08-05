<?php

namespace App\Services\ClinicalLearning;

use App\Models\ClinicalLearningSuggestion;

class LearningSuggestionRecorder implements LearningSuggestionRecorderInterface
{
    public function record(int $userId, array $payload): void
    {
        ClinicalLearningSuggestion::query()->create([
            'user_id' => $userId,
            'session_id' => $payload['session_id'] ?? null,
            'type' => $payload['type'],
            'detected_text' => $payload['detected_text'],
            'confirmed_text' => $payload['confirmed_text'],
            'confirmed_catalog_id' => $payload['confirmed_catalog_id'] ?? null,
            'action' => $payload['action'] ?? 'corrected',
            'meta' => $payload['meta'] ?? null,
        ]);
    }
}
