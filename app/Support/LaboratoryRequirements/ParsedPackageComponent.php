<?php

namespace App\Support\LaboratoryRequirements;

class ParsedPackageComponent
{
    public function __construct(
        public readonly string $rawText,
        public readonly string $normalizedText,
        public readonly int $index,
        public readonly string $confidence,
        public readonly array $evidence = [],
        public readonly ?string $unresolvedReason = null,
        public readonly ?string $inferredCategory = null,
    ) {}

    public function toArray(): array
    {
        return [
            'raw_text' => $this->rawText,
            'normalized_text' => $this->normalizedText,
            'index' => $this->index,
            'confidence' => $this->confidence,
            'evidence' => $this->evidence,
            'unresolved_reason' => $this->unresolvedReason,
            'inferred_category' => $this->inferredCategory,
        ];
    }
}
