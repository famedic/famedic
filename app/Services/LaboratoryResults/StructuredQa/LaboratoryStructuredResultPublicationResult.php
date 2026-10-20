<?php

namespace App\Services\LaboratoryResults\StructuredQa;

use App\Models\LaboratoryResultReport;

final class LaboratoryStructuredResultPublicationResult
{
    /**
     * @param  array<string, mixed>  $preview
     */
    public function __construct(
        public readonly LaboratoryResultReport $report,
        public readonly bool $published,
        public readonly bool $idempotent,
        public readonly bool $dryRun,
        public readonly array $preview,
    ) {}
}
