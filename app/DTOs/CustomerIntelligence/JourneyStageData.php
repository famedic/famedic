<?php

namespace App\DTOs\CustomerIntelligence;

final class JourneyStageData
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly int $count,
        public readonly float $percentOfTotal,
        public readonly ?float $conversionFromPrevious,
        public readonly ?float $dropoffPercent,
        public readonly ?float $avgDaysToNext,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'count' => $this->count,
            'percent_of_total' => $this->percentOfTotal,
            'conversion_from_previous' => $this->conversionFromPrevious,
            'dropoff_percent' => $this->dropoffPercent,
            'avg_days_to_next' => $this->avgDaysToNext,
            'count_formatted' => number_format($this->count),
            'percent_formatted' => number_format($this->percentOfTotal, 1).'%',
        ];
    }
}
