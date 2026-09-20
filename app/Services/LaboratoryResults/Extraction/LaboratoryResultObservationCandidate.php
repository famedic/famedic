<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryResultObservationValueType;

final class LaboratoryResultObservationCandidate
{
    public function __construct(
        public readonly string $analyteNameRaw,
        public readonly LaboratoryResultObservationValueType $valueType,
        public readonly ?float $numericValue = null,
        public readonly ?string $textValue = null,
        public readonly ?string $unit = null,
        public readonly ?string $unitRaw = null,
        public readonly ?float $referenceLow = null,
        public readonly ?float $referenceHigh = null,
        public readonly ?string $referenceText = null,
        public readonly ?int $sourcePage = null,
        public readonly float $confidence = 0.0,
        public readonly ?string $parseRule = null,
    ) {}
}
