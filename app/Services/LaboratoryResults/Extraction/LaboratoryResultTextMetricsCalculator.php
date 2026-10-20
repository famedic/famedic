<?php

namespace App\Services\LaboratoryResults\Extraction;

class LaboratoryResultTextMetricsCalculator
{
    public function calculate(LaboratoryResultTextExtractionResult $extraction): LaboratoryResultTextMetrics
    {
        $pageCount = max(1, $extraction->pageCount ?: ($extraction->fullText !== '' ? 1 : 0));
        $totalCharacters = $extraction->totalCharacters();
        $charactersPerPage = $pageCount > 0 ? $totalCharacters / $pageCount : 0.0;
        $approximateLineCount = $this->countNonEmptyLines($extraction->fullText);

        $minTotal = (int) config('laboratory-results.structured_extraction.min_total_characters', 50);
        $minPerPage = (int) config('laboratory-results.structured_extraction.min_characters_per_page', 20);

        $hasSufficientText = $totalCharacters >= $minTotal
            && ($extraction->pageCount <= 1 || $charactersPerPage >= $minPerPage);

        $textDensity = $pageCount > 0 ? min(1.0, $charactersPerPage / max(1, $minPerPage)) : 0.0;

        return new LaboratoryResultTextMetrics(
            pageCount: $extraction->pageCount,
            totalCharacters: $totalCharacters,
            charactersPerPage: round($charactersPerPage, 2),
            textDensity: round($textDensity, 4),
            approximateLineCount: $approximateLineCount,
            hasSufficientText: $hasSufficientText,
        );
    }

    private function countNonEmptyLines(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        return count(array_filter(
            preg_split('/\R/u', $text) ?: [],
            fn (string $line): bool => trim($line) !== ''
        ));
    }
}
