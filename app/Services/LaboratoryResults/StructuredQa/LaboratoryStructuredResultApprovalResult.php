<?php

namespace App\Services\LaboratoryResults\StructuredQa;

use App\Enums\LaboratoryStructuredResultPublicationApprovalStatus;
use App\Models\LaboratoryResultReport;

final class LaboratoryStructuredResultApprovalResult
{
    /**
     * @param  array<string, mixed>  $approval
     */
    public function __construct(
        public readonly LaboratoryResultReport $report,
        public readonly LaboratoryStructuredResultPublicationApprovalStatus $status,
        public readonly bool $idempotent,
        public readonly array $approval,
    ) {}
}
