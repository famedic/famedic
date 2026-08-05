<?php

namespace App\DTOs\CustomerIntelligence;

final class JourneyPathData
{
    /**
     * @param  list<string>  $steps
     */
    public function __construct(
        public readonly string $id,
        public readonly array $steps,
        public readonly int $users,
        public readonly float $percent,
        public readonly bool $converted,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'steps' => $this->steps,
            'users' => $this->users,
            'percent' => $this->percent,
            'percent_formatted' => number_format($this->percent, 1).'%',
            'converted' => $this->converted,
        ];
    }
}
