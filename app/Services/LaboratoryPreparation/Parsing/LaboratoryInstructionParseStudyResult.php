<?php

namespace App\Services\LaboratoryPreparation\Parsing;

final class LaboratoryInstructionParseStudyResult
{
    /**
     * @param  list<LaboratoryInstructionRequirement>  $requirements
     * @param  list<string>  $unrecognizedFragments
     */
    public function __construct(
        public readonly ?string $studyId,
        public readonly string $studyName,
        public readonly string $sourceText,
        public readonly array $requirements,
        public readonly array $unrecognizedFragments,
        public readonly bool $hasUnrecognizedContent,
        public readonly bool $isEmptySource,
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
            'requirements' => array_map(
                fn (LaboratoryInstructionRequirement $requirement) => $requirement->toArray(),
                $this->requirements,
            ),
            'unrecognized_fragments' => $this->unrecognizedFragments,
            'has_unrecognized_content' => $this->hasUnrecognizedContent,
            'is_empty_source' => $this->isEmptySource,
        ];
    }
}
