<?php

namespace App\Support\LaboratoryRequirements;

use App\Models\LaboratoryStore;

class BranchMatchResult
{
    /**
     * @param  array<int, GroupMatchResult>  $groups
     * @param  array<int, string>  $matchedRequirements
     * @param  array<int, string>  $missingRequirements
     * @param  array<int, string>  $reasons
     */
    public function __construct(
        public readonly LaboratoryStore $branch,
        public readonly bool $isCompatible,
        public readonly string $matchLevel,
        public readonly array $groups,
        public readonly array $matchedRequirements,
        public readonly array $missingRequirements,
        public readonly ?float $distanceKm,
        public readonly ?string $brand,
        public readonly BranchHoursInfo $hours,
        public readonly array $reasons = [],
    ) {}

    public function toArray(): array
    {
        return [
            'branch_id' => $this->branch->id,
            'branch_name' => $this->branch->name,
            'is_compatible' => $this->isCompatible,
            'match_level' => $this->matchLevel,
            'groups' => array_map(fn (GroupMatchResult $group) => $group->toArray(), $this->groups),
            'matched_requirements' => $this->matchedRequirements,
            'missing_requirements' => $this->missingRequirements,
            'distance_km' => $this->distanceKm,
            'brand' => $this->brand,
            'hours' => $this->hours->toArray(),
            'reasons' => $this->reasons,
        ];
    }
}
