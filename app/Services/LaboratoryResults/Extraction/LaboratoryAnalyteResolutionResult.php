<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryAnalyteResolutionStatus;
use App\Models\LaboratoryAnalyte;

final class LaboratoryAnalyteResolutionResult
{
    public function __construct(
        public readonly ?LaboratoryAnalyte $analyte,
        public readonly LaboratoryAnalyteResolutionStatus $status,
        public readonly string $normalizedName,
        public readonly ?string $identityKey,
        public readonly string $analyteNameRaw,
    ) {}

    public function analyteCode(): ?string
    {
        return $this->analyte?->code;
    }
}
