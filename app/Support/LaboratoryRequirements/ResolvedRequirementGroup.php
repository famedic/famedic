<?php

namespace App\Support\LaboratoryRequirements;

class ResolvedRequirementGroup
{
    /**
     * @param  array<int, ResolvedRequirement>  $requirements
     */
    public function __construct(
        public readonly string $groupKey,
        public readonly string $operator,
        public readonly bool $required,
        public readonly string $source,
        public readonly string $confidence,
        public readonly array $requirements,
        public readonly array $evidence = [],
    ) {}

    public function toArray(): array
    {
        return [
            'group_key' => $this->groupKey,
            'operator' => $this->operator,
            'required' => $this->required,
            'source' => $this->source,
            'confidence' => $this->confidence,
            'requirements' => array_map(fn (ResolvedRequirement $requirement) => $requirement->toArray(), $this->requirements),
            'evidence' => $this->evidence,
        ];
    }
}
