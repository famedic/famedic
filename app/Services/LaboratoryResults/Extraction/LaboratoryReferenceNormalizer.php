<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryReferenceComparisonOutcome;

final class LaboratoryReferenceNormalizer
{
    private const NUMERIC_EPSILON = 0.001;

    public function parse(?string $referenceText): LaboratoryReferenceParseResult
    {
        if ($referenceText === null || trim($referenceText) === '') {
            return new LaboratoryReferenceParseResult(null, null, null, 'empty');
        }

        $original = trim($referenceText);
        $normalized = $this->normalizeText($original);

        if ($normalized !== null && preg_match('/^(?:deseable|normal|bajo|alto|lim[ií]trofe)\s*:?\s*([<>]=?\s*[\d]+(?:[.,]\d+)?)$/u', $normalized, $matches)) {
            $normalized = $this->compactInequalityReference($matches[1]);
        }

        if ($normalized !== null && preg_match('/^<=\s*([\d]+(?:[.,]\d+)?)$/u', $normalized, $matches)) {
            return new LaboratoryReferenceParseResult($original, null, $this->parseNumber($matches[1]), 'lt');
        }

        if ($normalized !== null && preg_match('/^>=\s*([\d]+(?:[.,]\d+)?)$/u', $normalized, $matches)) {
            return new LaboratoryReferenceParseResult($original, $this->parseNumber($matches[1]), null, 'gt');
        }

        if ($normalized !== null && preg_match('/^<\s*([\d]+(?:[.,]\d+)?)$/u', $normalized, $matches)) {
            return new LaboratoryReferenceParseResult($original, null, $this->parseNumber($matches[1]), 'lt');
        }

        if ($normalized !== null && preg_match('/^>\s*([\d]+(?:[.,]\d+)?)$/u', $normalized, $matches)) {
            return new LaboratoryReferenceParseResult($original, $this->parseNumber($matches[1]), null, 'gt');
        }

        if (preg_match('/^mayor de\s+([\d]+(?:[.,]\d+)?)$/u', $normalized, $matches)) {
            return new LaboratoryReferenceParseResult($original, $this->parseNumber($matches[1]), null, 'gt');
        }

        if (preg_match('/^menor de\s+([\d]+(?:[.,]\d+)?)$/u', $normalized, $matches)) {
            return new LaboratoryReferenceParseResult($original, null, $this->parseNumber($matches[1]), 'lt');
        }

        if (preg_match('/^([\d]+(?:[.,]\d+)?)\s*[-–—]\s*([\d]+(?:[.,]\d+)?)$/u', $normalized, $matches)) {
            return new LaboratoryReferenceParseResult(
                $original,
                $this->parseNumber($matches[1]),
                $this->parseNumber($matches[2]),
                'range',
            );
        }

        return new LaboratoryReferenceParseResult($original, null, null, 'unknown');
    }

    public function compare(
        ?string $textReferenceText,
        ?float $textReferenceLow,
        ?float $textReferenceHigh,
        ?string $visionReferenceText,
        ?float $visionReferenceLow,
        ?float $visionReferenceHigh,
    ): LaboratoryReferenceComparisonOutcome {
        $textParsed = $this->parse($textReferenceText);
        $visionParsed = $this->parse($visionReferenceText);

        $textLow = $textReferenceLow ?? $textParsed->referenceLow;
        $textHigh = $textReferenceHigh ?? $textParsed->referenceHigh;
        $visionLow = $visionReferenceLow ?? $visionParsed->referenceLow;
        $visionHigh = $visionReferenceHigh ?? $visionParsed->referenceHigh;

        if ($textLow === null && $textHigh === null && $visionLow === null && $visionHigh === null) {
            return LaboratoryReferenceComparisonOutcome::Unknown;
        }

        $lowMatches = $this->numericEquals($textLow, $visionLow);
        $highMatches = $this->numericEquals($textHigh, $visionHigh);

        if ($lowMatches && $highMatches) {
            return LaboratoryReferenceComparisonOutcome::Match;
        }

        $textNormalized = $this->normalizeText($textReferenceText);
        $visionNormalized = $this->normalizeText($visionReferenceText);

        if ($textNormalized !== null && $textNormalized === $visionNormalized) {
            return LaboratoryReferenceComparisonOutcome::Match;
        }

        if (! $lowMatches || ! $highMatches) {
            return LaboratoryReferenceComparisonOutcome::Conflict;
        }

        return LaboratoryReferenceComparisonOutcome::Unknown;
    }

    public function normalizeText(?string $referenceText): ?string
    {
        if ($referenceText === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($referenceText), 'UTF-8');
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = str_replace(',', '.', $normalized);

        return $normalized === '' ? null : $normalized;
    }

    private function compactInequalityReference(string $reference): string
    {
        return preg_replace('/\s+/u', '', str_replace(',', '.', trim($reference))) ?? trim($reference);
    }

    private function parseNumber(string $value): ?float
    {
        $normalized = str_replace(',', '.', trim($value));

        if (! is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    private function numericEquals(?float $left, ?float $right): bool
    {
        if ($left === null && $right === null) {
            return true;
        }

        if ($left === null || $right === null) {
            return false;
        }

        return abs($left - $right) <= self::NUMERIC_EPSILON;
    }
}
