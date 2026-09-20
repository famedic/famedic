<?php

namespace App\Services\LaboratoryResults\Extraction;

final class LaboratoryResultExtractionComparisonSummary
{
    /**
     * @param  list<LaboratoryResultExtractionComparisonItem>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $matchCount,
        public readonly int $conflictCount,
        public readonly int $visionOnlyCount,
        public readonly int $textOnlyCount,
        public readonly int $unresolvedCount,
    ) {}

    public function hasConflicts(): bool
    {
        return $this->conflictCount > 0;
    }

    /**
     * @return array<string, int>
     */
    public function metrics(): array
    {
        return [
            'matches' => $this->matchCount,
            'conflicts' => $this->conflictCount,
            'vision_only' => $this->visionOnlyCount,
            'text_only' => $this->textOnlyCount,
            'unresolved' => $this->unresolvedCount,
        ];
    }
}
