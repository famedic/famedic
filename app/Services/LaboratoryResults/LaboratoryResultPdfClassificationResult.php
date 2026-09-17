<?php

namespace App\Services\LaboratoryResults;

use App\Enums\LaboratoryResultPdfClassification;

final class LaboratoryResultPdfClassificationResult
{
    public function __construct(
        public readonly LaboratoryResultPdfClassification $classification,
        public readonly string $reason,
        public readonly ?string $matchedRule = null,
        public readonly ?float $confidence = null,
    ) {}
}
