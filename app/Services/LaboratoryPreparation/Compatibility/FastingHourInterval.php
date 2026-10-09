<?php

namespace App\Services\LaboratoryPreparation\Compatibility;

use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionRequirement;

final class FastingHourInterval
{
    public function __construct(
        public readonly int $minHours,
        public readonly ?int $maxHours,
        public readonly LaboratoryInstructionRequirement $requirement,
        public readonly ?int $linkedMaxAgeYears = null,
    ) {
        if ($minHours < 0) {
            throw new \InvalidArgumentException('Fasting minimum hours must be zero or positive.');
        }

        if ($maxHours !== null && $maxHours < $minHours) {
            throw new \InvalidArgumentException('Fasting maximum hours must be greater than or equal to minimum hours.');
        }
    }

    public static function fromRequirement(
        LaboratoryInstructionRequirement $requirement,
        ?int $linkedMaxAgeYears = null,
    ): ?self {
        $value = $requirement->normalizedValue;
        $kind = (string) ($value['kind'] ?? '');

        return match ($kind) {
            'exact' => new self(
                minHours: (int) $value['hours'],
                maxHours: null,
                requirement: $requirement,
                linkedMaxAgeYears: $linkedMaxAgeYears,
            ),
            'minimum' => new self(
                minHours: (int) $value['min_hours'],
                maxHours: null,
                requirement: $requirement,
                linkedMaxAgeYears: $linkedMaxAgeYears,
            ),
            'closed_range' => new self(
                minHours: (int) $value['min_hours'],
                maxHours: (int) $value['max_hours'],
                requirement: $requirement,
                linkedMaxAgeYears: $linkedMaxAgeYears,
            ),
            default => null,
        };
    }

    public function intersect(self $other): ?self
    {
        $min = max($this->minHours, $other->minHours);
        $max = $this->combineMax($this->maxHours, $other->maxHours);

        if ($max !== null && $min > $max) {
            return null;
        }

        return new self(
            minHours: $min,
            maxHours: $max,
            requirement: $this->requirement,
        );
    }

    /**
     * @return array{minimum_hours: int, maximum_hours: int|null, exact_hours: int|null}
     */
    public function toConsolidatedShape(): array
    {
        if ($this->maxHours !== null && $this->minHours === $this->maxHours) {
            return [
                'minimum_hours' => $this->minHours,
                'maximum_hours' => $this->maxHours,
                'exact_hours' => $this->minHours,
            ];
        }

        return [
            'minimum_hours' => $this->minHours,
            'maximum_hours' => $this->maxHours,
            'exact_hours' => null,
        ];
    }

    private function combineMax(?int $left, ?int $right): ?int
    {
        if ($left === null || $right === null) {
            return $left ?? $right;
        }

        return min($left, $right);
    }
}
