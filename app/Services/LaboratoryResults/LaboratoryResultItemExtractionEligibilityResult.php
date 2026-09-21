<?php

namespace App\Services\LaboratoryResults;

final class LaboratoryResultItemExtractionEligibilityResult
{
    public function __construct(
        public readonly bool $eligible,
        public readonly string $reason,
        public readonly string $source,
    ) {}
}
