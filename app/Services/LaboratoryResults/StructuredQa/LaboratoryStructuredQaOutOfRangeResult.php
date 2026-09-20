<?php

namespace App\Services\LaboratoryResults\StructuredQa;

final class LaboratoryStructuredQaOutOfRangeResult
{
    /**
     * @param  list<array<string, mixed>>  $preview
     */
    public function __construct(
        public readonly int $observationsInput,
        public readonly int $evaluatedCount,
        public readonly int $skippedCount,
        public readonly int $updatedCount,
        public readonly int $duplicatePrevented,
        public readonly int $lowCount,
        public readonly int $normalCount,
        public readonly int $highCount,
        public readonly int $unknownCount,
        public readonly int $notApplicableCount,
        public readonly bool $dryRun,
        public readonly array $preview,
    ) {}
}
