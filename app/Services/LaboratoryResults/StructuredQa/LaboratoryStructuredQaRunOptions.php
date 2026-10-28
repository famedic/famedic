<?php

namespace App\Services\LaboratoryResults\StructuredQa;

final class LaboratoryStructuredQaRunOptions
{
    public function __construct(
        public readonly bool $dryRun = false,
        public readonly ?int $versionId = null,
        public readonly ?int $limit = null,
        public readonly bool $evaluateOutOfRange = false,
        public readonly bool $evaluatePromotion = false,
        public readonly bool $publishApproved = false,
        public readonly ?int $reportId = null,
    ) {}
}
