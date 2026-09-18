<?php

namespace App\Support\LaboratoryRequirements;

class BranchResolution
{
    /**
     * @param  array<int, BranchMatchResult>  $branches
     * @param  array<string, array<int, BranchMatchResult>>  $brands
     * @param  array<int, string>  $reasons
     */
    public function __construct(
        public readonly array $branches,
        public readonly array $brands = [],
        public readonly array $reasons = [],
    ) {}

    public function toArray(): array
    {
        return [
            'branches' => array_map(fn (BranchMatchResult $branch) => $branch->toArray(), $this->branches),
            'brands' => collect($this->brands)
                ->map(fn (array $branches) => array_map(fn (BranchMatchResult $branch) => $branch->toArray(), $branches))
                ->all(),
            'reasons' => $this->reasons,
        ];
    }
}
