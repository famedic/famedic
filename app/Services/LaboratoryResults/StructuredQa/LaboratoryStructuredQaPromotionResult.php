<?php

namespace App\Services\LaboratoryResults\StructuredQa;

final class LaboratoryStructuredQaPromotionResult
{
    /**
     * @param  array<string, int>  $reasonCodeCounts
     * @param  list<array<string, mixed>>  $preview
     */
    public function __construct(
        public readonly int $candidatesInput,
        public readonly int $validatedCount,
        public readonly int $needsReviewCount,
        public readonly int $rejectedCount,
        public readonly int $updatedCount,
        public readonly int $duplicatePrevented,
        public readonly bool $dryRun,
        public readonly array $reasonCodeCounts,
        public readonly array $preview,
    ) {}
}
