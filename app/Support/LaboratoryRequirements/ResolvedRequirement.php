<?php

namespace App\Support\LaboratoryRequirements;

class ResolvedRequirement
{
    public function __construct(
        public readonly ?string $capabilitySlug,
        public readonly ?int $capabilityId,
        public readonly ?string $label,
        public readonly bool $required,
        public readonly string $source,
        public readonly string $confidence,
        public readonly array $evidence = [],
        public readonly ?string $notes = null,
    ) {}

    public function toArray(): array
    {
        return [
            'capability_slug' => $this->capabilitySlug,
            'capability_id' => $this->capabilityId,
            'label' => $this->label,
            'required' => $this->required,
            'source' => $this->source,
            'confidence' => $this->confidence,
            'evidence' => $this->evidence,
            'notes' => $this->notes,
        ];
    }
}
