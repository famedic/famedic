<?php

namespace App\Services\LaboratoryPreparation\Parsing;

final class LaboratoryInstructionRequirement
{
    /**
     * @param  array<string, mixed>  $normalizedValue
     * @param  array<string, mixed>  $qualifiers
     */
    public function __construct(
        public readonly ?string $studyId,
        public readonly string $studyName,
        public readonly string $sourceText,
        public readonly string $sourceSpan,
        public readonly int $sourceSpanStart,
        public readonly int $sourceSpanEnd,
        public readonly string $category,
        public readonly string $requirementType,
        public readonly array $normalizedValue,
        public readonly ?string $unit,
        public readonly array $qualifiers,
        public readonly string $recognitionStatus,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'study_id' => $this->studyId,
            'study_name' => $this->studyName,
            'source_text' => $this->sourceText,
            'source_span' => $this->sourceSpan,
            'source_span_start' => $this->sourceSpanStart,
            'source_span_end' => $this->sourceSpanEnd,
            'category' => $this->category,
            'requirement_type' => $this->requirementType,
            'normalized_value' => $this->normalizedValue,
            'unit' => $this->unit,
            'qualifiers' => $this->qualifiers,
            'recognition_status' => $this->recognitionStatus,
        ];
    }
}
