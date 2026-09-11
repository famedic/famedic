<?php

namespace App\Services\LaboratoryStores\Gda;

class GdaImportPlan
{
    public function __construct(
        public readonly array $rows,
        public readonly array $totals,
        public readonly ?int $runId = null,
    ) {}
}
