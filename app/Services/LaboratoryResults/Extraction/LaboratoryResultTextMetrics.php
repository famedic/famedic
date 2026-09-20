<?php

namespace App\Services\LaboratoryResults\Extraction;

final class LaboratoryResultTextMetrics
{
    public function __construct(
        public readonly int $pageCount,
        public readonly int $totalCharacters,
        public readonly float $charactersPerPage,
        public readonly float $textDensity,
        public readonly int $approximateLineCount,
        public readonly bool $hasSufficientText,
    ) {}
}
