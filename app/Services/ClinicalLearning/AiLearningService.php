<?php

namespace App\Services\ClinicalLearning;

/**
 * Facade-ready entry for the future AI Learning product surface.
 * Wire Inertia page later without changing LearningSuggestionRecorder.
 */
class AiLearningService
{
    public function __construct(
        private FrequentCorrectionsQuery $frequentCorrections,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboardPayload(int $limit = 50): array
    {
        return [
            'meta' => [
                'product' => 'AI Learning',
                'status' => 'prepared',
                'note' => 'Pantalla futura. Este payload ya expone correcciones frecuentes.',
            ],
            'frequent_corrections' => $this->frequentCorrections->top($limit)->values()->all(),
            'filters' => [
                'types' => ['laboratory', 'medication'],
            ],
        ];
    }
}
