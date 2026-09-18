<?php

namespace App\Support\LaboratoryRequirements;

class ResolvedStudyRequirements
{
    /**
     * @param  array<int, ResolvedRequirementGroup>  $groups
     * @param  array<int, string>  $unresolvedReasons
     */
    public function __construct(
        public readonly int $testId,
        public readonly ?string $gdaId,
        public readonly ?int $categoryId,
        public readonly string $testName,
        public readonly string $confidence,
        public readonly array $groups = [],
        public readonly array $unresolvedReasons = [],
        public readonly array $evidence = [],
    ) {}

    public function toArray(): array
    {
        return [
            'test_id' => $this->testId,
            'gda_id' => $this->gdaId,
            'category_id' => $this->categoryId,
            'test_name' => $this->testName,
            'confidence' => $this->confidence,
            'groups' => array_map(fn (ResolvedRequirementGroup $group) => $group->toArray(), $this->groups),
            'unresolved_reasons' => $this->unresolvedReasons,
            'evidence' => $this->evidence,
        ];
    }
}
