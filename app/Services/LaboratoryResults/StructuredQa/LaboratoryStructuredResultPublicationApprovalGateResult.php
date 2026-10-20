<?php

namespace App\Services\LaboratoryResults\StructuredQa;

final class LaboratoryStructuredResultPublicationApprovalGateResult
{
    /**
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $reasonsHuman
     */
    public function __construct(
        public readonly bool $canApprove,
        public readonly array $reasonCodes,
        public readonly array $reasonsHuman,
    ) {}
}
