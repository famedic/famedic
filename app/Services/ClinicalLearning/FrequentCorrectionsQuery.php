<?php

namespace App\Services\ClinicalLearning;

use App\Models\ClinicalLearningSuggestion;
use Illuminate\Support\Collection;

/**
 * Prepared for the future Admin → AI Learning screen.
 * Not exposed as a full UI in this sprint.
 */
class FrequentCorrectionsQuery
{
    /**
     * Most frequent detected → confirmed pairs.
     *
     * @return Collection<int, object{
     *   type: string,
     *   detected_text: string,
     *   confirmed_text: string,
     *   occurrences: int,
     *   last_seen_at: string|null
     * }>
     */
    public function top(int $limit = 50, ?string $type = null): Collection
    {
        return ClinicalLearningSuggestion::query()
            ->selectRaw('type, detected_text, confirmed_text, COUNT(*) as occurrences, MAX(created_at) as last_seen_at')
            ->when($type, fn ($q) => $q->where('type', $type))
            ->where('action', 'corrected')
            ->groupBy('type', 'detected_text', 'confirmed_text')
            ->orderByDesc('occurrences')
            ->limit($limit)
            ->get();
    }
}
