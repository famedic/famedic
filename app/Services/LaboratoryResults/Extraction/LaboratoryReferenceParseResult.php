<?php

namespace App\Services\LaboratoryResults\Extraction;

final class LaboratoryReferenceParseResult
{
    public function __construct(
        public readonly ?string $referenceTextOriginal,
        public readonly ?float $referenceLow,
        public readonly ?float $referenceHigh,
        public readonly string $kind,
    ) {}
}
