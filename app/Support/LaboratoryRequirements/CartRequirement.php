<?php

namespace App\Support\LaboratoryRequirements;

class CartRequirement
{
    /**
     * @param  array<int, string>  $sources
     * @param  array<int, string>  $confidences
     * @param  array<int, array<string, mixed>>  $studies
     * @param  array<int, array<string, mixed>>  $components
     */
    public function __construct(
        public readonly ?string $capabilitySlug,
        public readonly ?int $capabilityId,
        public readonly ?string $label,
        public readonly bool $required,
        public readonly array $sources,
        public readonly array $confidences,
        public readonly array $studies,
        public readonly array $components = [],
        public readonly array $evidence = [],
    ) {}

    public function toArray(): array
    {
        return [
            'capability_slug' => $this->capabilitySlug,
            'capability_id' => $this->capabilityId,
            'label' => $this->label,
            'required' => $this->required,
            'sources' => $this->sources,
            'confidences' => $this->confidences,
            'studies' => $this->studies,
            'components' => $this->components,
            'evidence' => $this->evidence,
        ];
    }
}
