<?php

namespace App\DTOs\CustomerIntelligence;

final class CohortRetentionRowData
{
    /**
     * @param  list<array{week: int, retained: int, percent: float|null}>  $weeks
     */
    public function __construct(
        public readonly string $cohortKey,
        public readonly string $cohortLabel,
        public readonly int $size,
        public readonly array $weeks,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'cohort_key' => $this->cohortKey,
            'cohort_label' => $this->cohortLabel,
            'size' => $this->size,
            'size_formatted' => number_format($this->size),
            'weeks' => $this->weeks,
        ];
    }
}
