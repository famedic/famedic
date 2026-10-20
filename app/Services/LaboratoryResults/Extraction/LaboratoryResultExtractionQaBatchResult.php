<?php

namespace App\Services\LaboratoryResults\Extraction;

final class LaboratoryResultExtractionQaBatchResult
{
    /**
     * @param  list<LaboratoryResultExtractionQaBatchDocumentResult>  $documents
     * @param  array<string, int>  $categoryCounts
     * @param  array<string, array<string, int>>  $patternsBySource
     * @param  array<string, array<string, int>>  $patternsByFallback
     * @param  array<string, array<string, int>>  $patternsByTextStatus
     * @param  array<string, array<string, int>>  $patternsByPageCount
     * @param  array<string, list<int>>  $aliasCandidateVersionIds
     */
    public function __construct(
        public readonly array $documents,
        public readonly int $totalVersions,
        public readonly int $versionsWithText,
        public readonly int $versionsWithVision,
        public readonly int $totalComparablePairs,
        public readonly int $totalMatch,
        public readonly int $totalConflict,
        public readonly int $totalTextOnly,
        public readonly int $totalVisionOnly,
        public readonly int $totalUnresolved,
        public readonly ?float $matchRate,
        public readonly ?float $conflictRate,
        public readonly ?float $visionOnlyRate,
        public readonly ?float $textOnlyRate,
        public readonly ?float $visionCoverage,
        public readonly array $categoryCounts,
        public readonly array $patternsBySource,
        public readonly array $patternsByFallback,
        public readonly array $patternsByTextStatus,
        public readonly array $patternsByPageCount,
        public readonly array $aliasCandidateVersionIds,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function allConflicts(bool $verbose): array
    {
        $conflicts = [];

        foreach ($this->documents as $document) {
            foreach ($document->conflicts as $conflict) {
                if (! $verbose) {
                    unset($conflict['text_value'], $conflict['vision_value'], $conflict['text_unit'], $conflict['vision_unit'], $conflict['text_reference'], $conflict['vision_reference']);
                }

                $conflicts[] = $conflict;
            }
        }

        return $conflicts;
    }

    public function formatPercentage(?float $rate): string
    {
        if ($rate === null) {
            return 'n/a';
        }

        return number_format($rate * 100, 2).'%';
    }
}
