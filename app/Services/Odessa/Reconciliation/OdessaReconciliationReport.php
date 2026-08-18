<?php

namespace App\Services\Odessa\Reconciliation;

class OdessaReconciliationReport
{
    /**
     * @param  list<OdessaReconciliationResult>  $results
     */
    public function __construct(
        public readonly string $sourcePath,
        public readonly array $results,
        public readonly OdessaReconciliationSummary $summary,
        public readonly ?string $exportPath = null,
    ) {}
}
