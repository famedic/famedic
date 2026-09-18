<?php

namespace App\Support\LaboratoryRequirements;

class CartRequirementGroup
{
    /**
     * @param  array<int, CartRequirement>  $requirements
     * @param  array<int, string>  $sources
     * @param  array<int, string>  $confidences
     * @param  array<int, array<string, mixed>>  $studies
     */
    public function __construct(
        public readonly string $groupKey,
        public readonly string $operator,
        public readonly bool $required,
        public readonly array $requirements,
        public readonly array $sources,
        public readonly array $confidences,
        public readonly array $studies,
        public readonly array $evidence = [],
    ) {}

    public function toArray(): array
    {
        return [
            'group_key' => $this->groupKey,
            'operator' => $this->operator,
            'required' => $this->required,
            'requirements' => array_map(fn (CartRequirement $requirement) => $requirement->toArray(), $this->requirements),
            'sources' => $this->sources,
            'confidences' => $this->confidences,
            'studies' => $this->studies,
            'evidence' => $this->evidence,
        ];
    }
}
