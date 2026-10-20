<?php

namespace App\Services\LaboratoryResults\StructuredQa;

final class LaboratoryStructuredQaRunResult
{
    /**
     * @param  list<array<string, mixed>>  $validatedPreview
     * @param  list<array<string, mixed>>  $rejected
     * @param  list<array<string, mixed>>  $persisted
     */
    public function __construct(
        public readonly int $candidatesInput,
        public readonly int $validCount,
        public readonly int $rejectedCount,
        public readonly int $persistedReportCount,
        public readonly int $observationsPersisted,
        public readonly int $observationsDuplicatePrevented,
        public readonly int $cbcValid,
        public readonly int $nonCbcValid,
        public readonly int $cbcPersisted,
        public readonly int $nonCbcPersisted,
        public readonly bool $dryRun,
        public readonly array $validatedPreview,
        public readonly array $rejected,
        public readonly array $persisted,
    ) {}
}
