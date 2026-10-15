<?php

namespace App\Services\LaboratoryPreparation\Parsing;

final class LaboratoryInstructionParseResult
{
    /**
     * @param  list<LaboratoryInstructionParseStudyResult>  $studies
     */
    public function __construct(
        public readonly array $studies,
        public readonly bool $hasUnrecognizedContent,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'studies' => array_map(
                fn (LaboratoryInstructionParseStudyResult $study) => $study->toArray(),
                $this->studies,
            ),
            'has_unrecognized_content' => $this->hasUnrecognizedContent,
        ];
    }
}
