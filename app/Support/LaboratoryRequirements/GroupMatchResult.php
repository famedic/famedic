<?php

namespace App\Support\LaboratoryRequirements;

class GroupMatchResult
{
    public function __construct(
        public readonly string $groupKey,
        public readonly string $operator,
        public readonly bool $required,
        public readonly bool $isSatisfied,
        public readonly array $matchedCapabilities,
        public readonly array $missingCapabilities,
        public readonly string $confidence,
        public readonly array $sources = [],
    ) {}

    public function toArray(): array
    {
        return [
            'group_key' => $this->groupKey,
            'operator' => $this->operator,
            'required' => $this->required,
            'is_satisfied' => $this->isSatisfied,
            'matched_capabilities' => $this->matchedCapabilities,
            'missing_capabilities' => $this->missingCapabilities,
            'confidence' => $this->confidence,
            'sources' => $this->sources,
        ];
    }
}
