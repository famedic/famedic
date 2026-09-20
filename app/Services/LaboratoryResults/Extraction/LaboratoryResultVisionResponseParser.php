<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryResultObservationValueType;
use InvalidArgumentException;

class LaboratoryResultVisionResponseParser
{
    private const PARSE_RULE = 'vision_v3';

    /**
     * @var list<string>
     */
    private const NOISE_ANALYTE_FRAGMENTS = [
        'quimica sanguinea',
        'biometria hematica',
        'examen general de orina',
        'cualquier aclaracion',
        'riesgo moderado',
        'riesgo alto',
        'riesgo bajo',
    ];

    /**
     * @return list<LaboratoryResultObservationCandidate>
     */
    public function parse(array $payload): array
    {
        if (! array_key_exists('observations', $payload) || ! is_array($payload['observations'])) {
            throw new InvalidArgumentException('Vision response missing observations array.');
        }

        $candidates = [];

        foreach ($payload['observations'] as $index => $observation) {
            if (! is_array($observation)) {
                throw new InvalidArgumentException('Vision observation at index '.$index.' is not an object.');
            }

            $candidate = $this->parseObservation($observation, $index);

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $observation
     */
    private function parseObservation(array $observation, int $index): ?LaboratoryResultObservationCandidate
    {
        $name = trim((string) ($observation['analyte_name_raw'] ?? ''));

        if ($name === '' || $this->isNoiseAnalyte($name)) {
            return null;
        }

        $valueTypeRaw = mb_strtolower(trim((string) ($observation['value_type'] ?? 'text')), 'UTF-8');
        $valueRaw = $observation['value'] ?? null;
        $valueString = is_string($valueRaw) ? trim($valueRaw) : (is_numeric($valueRaw) ? (string) $valueRaw : null);

        $valueType = match ($valueTypeRaw) {
            'numeric' => LaboratoryResultObservationValueType::Numeric,
            'qualitative' => LaboratoryResultObservationValueType::Qualitative,
            'text' => LaboratoryResultObservationValueType::Comment,
            default => LaboratoryResultObservationValueType::Unknown,
        };

        [$valueString, $valueHadDocumentFlag] = $this->stripDocumentFlagsFromValue($valueString);

        if ($this->looksLikeReferenceRangeValue($valueString)) {
            return null;
        }

        $unit = $this->nullableString($observation['unit'] ?? null);

        if ($this->hasContradictoryUnit($unit)) {
            return null;
        }

        if ($this->hasKnownUnitRowConfusion($name, $unit)) {
            return null;
        }

        $referenceText = $this->nullableString($observation['reference_text'] ?? null);
        [$referenceLow, $referenceHigh, $referenceText] = $this->parseReference($referenceText);

        $confidence = $this->resolveConfidence(
            rawConfidence: $observation['confidence'] ?? null,
            valueType: $valueType,
            unit: $unit,
            valueHadDocumentFlag: $valueHadDocumentFlag,
            referenceText: $referenceText,
        );

        $sourcePage = is_numeric($observation['source_page'] ?? null)
            ? (int) $observation['source_page']
            : null;

        if ($valueType === LaboratoryResultObservationValueType::Numeric) {
            $numeric = $this->parseNumber($valueString ?? '');

            if ($numeric === null) {
                return null;
            }

            if ($this->hasValueIncompatibleWithPlateletRow($name, $numeric, $referenceLow, $referenceHigh)) {
                return null;
            }

            return new LaboratoryResultObservationCandidate(
                analyteNameRaw: $name,
                valueType: $valueType,
                numericValue: $numeric,
                unit: $unit,
                unitRaw: $unit,
                referenceLow: $referenceLow,
                referenceHigh: $referenceHigh,
                referenceText: $referenceText,
                sourcePage: $sourcePage,
                confidence: $confidence,
                parseRule: self::PARSE_RULE,
            );
        }

        if ($valueString === null || $valueString === '') {
            return null;
        }

        return new LaboratoryResultObservationCandidate(
            analyteNameRaw: $name,
            valueType: $valueType,
            textValue: $valueString,
            unit: $unit,
            unitRaw: $unit,
            referenceLow: $referenceLow,
            referenceHigh: $referenceHigh,
            referenceText: $referenceText,
            sourcePage: $sourcePage,
            confidence: $confidence,
            parseRule: self::PARSE_RULE,
        );
    }

    /**
     * @return array{0: ?string, 1: bool}
     */
    private function stripDocumentFlagsFromValue(?string $value): array
    {
        if ($value === null || $value === '') {
            return [null, false];
        }

        if (preg_match('/^([\d]+(?:[.,]\d+)?)\s*\([A-Za-z]\)\s*$/u', $value, $matches)) {
            return [$matches[1], true];
        }

        if (preg_match('/^\([A-Za-z]\)\s*([\d]+(?:[.,]\d+)?)\s*$/u', $value, $matches)) {
            return [$matches[1], true];
        }

        return [$value, false];
    }

    private function looksLikeReferenceRangeValue(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return (bool) preg_match('/^[\d]+(?:[.,]\d+)?\s*[-–—]\s*[\d]+(?:[.,]\d+)?$/u', $value);
    }

    private function hasContradictoryUnit(?string $unit): bool
    {
        if ($unit === null) {
            return false;
        }

        $normalized = mb_strtolower($unit, 'UTF-8');

        $hasPercent = str_contains($normalized, '%');
        $hasPerVolume = (bool) preg_match('/\/\s*(u|µ|micro|mil|miles|10\^?6|x10)/u', $normalized);

        return $hasPercent && $hasPerVolume;
    }

    /**
     * Rechaza confusiones visuales documentadas en corpus GDA (columna/fila incorrecta).
     * No infiere unidades clínicas — detecta pares analito+unidad imposibles por fila.
     */
    private function hasKnownUnitRowConfusion(string $analyteNameRaw, ?string $unit): bool
    {
        if ($unit === null) {
            return false;
        }

        $analyte = LaboratoryAnalyteNameNormalizer::normalize($analyteNameRaw);
        $unitNormalized = mb_strtolower(str_replace([' ', 'µ', 'μ'], ['', 'u', 'u'], trim($unit)), 'UTF-8');

        if ($this->isHgmAnalyteName($analyte)) {
            return in_array($unitNormalized, ['g/dl', 'g/100ml'], true);
        }

        if ($analyte === 'chcm' || str_contains($analyte, 'concentracion de hemoglobina corpuscular')) {
            return $unitNormalized === 'pg';
        }

        if ($analyte === 'hemoglobina' || $analyte === 'hb') {
            return $unitNormalized === 'pg';
        }

        return false;
    }

    /**
     * Plaquetas con referencia típica (centenas) pero valor de fila diferencial (unidades).
     */
    private function hasValueIncompatibleWithPlateletRow(
        string $analyteNameRaw,
        float $numericValue,
        ?float $referenceLow,
        ?float $referenceHigh,
    ): bool {
        $analyte = LaboratoryAnalyteNameNormalizer::normalize($analyteNameRaw);

        if (! str_contains($analyte, 'plaqueta') && $analyte !== 'plt') {
            return false;
        }

        $refLow = $referenceLow ?? $referenceHigh;

        if ($refLow === null || $refLow < 80) {
            return false;
        }

        return $numericValue < ($refLow * 0.5);
    }

    private function isHgmAnalyteName(string $normalizedAnalyte): bool
    {
        if ($normalizedAnalyte === 'hgm') {
            return true;
        }

        return str_contains($normalizedAnalyte, 'hemoglobina corpuscular media')
            && ! str_contains($normalizedAnalyte, 'concentracion');
    }

    private function isNoiseAnalyte(string $name): bool
    {
        $normalized = mb_strtolower(trim($name), 'UTF-8');

        foreach (self::NOISE_ANALYTE_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function resolveConfidence(
        mixed $rawConfidence,
        LaboratoryResultObservationValueType $valueType,
        ?string $unit,
        bool $valueHadDocumentFlag,
        ?string $referenceText,
    ): float {
        $confidence = is_numeric($rawConfidence)
            ? max(0.0, min(1.0, (float) $rawConfidence))
            : 0.5;

        if ($valueType === LaboratoryResultObservationValueType::Numeric && $unit === null) {
            $confidence = min($confidence, 0.55);
        }

        if ($valueHadDocumentFlag) {
            $confidence = min($confidence, 0.85);
        }

        if ($referenceText === null && $confidence > 0.75) {
            $confidence = min($confidence, 0.75);
        }

        return $confidence;
    }

    /**
     * @return array{0: ?float, 1: ?float, 2: ?string}
     */
    private function parseReference(?string $referenceText): array
    {
        if ($referenceText === null || trim($referenceText) === '') {
            return [null, null, null];
        }

        $referencePart = trim($referenceText);

        if (preg_match('/^(?:deseable|normal|bajo|alto|lim[ií]trofe)\s*:?\s*([<>]=?\s*[\d]+(?:[.,]\d+)?)$/ui', $referencePart, $matches)) {
            $referencePart = $this->compactInequalityReference($matches[1]);
        }

        if (preg_match('/^<=\s*([\d]+(?:[.,]\d+)?)$/u', $referencePart, $matches)) {
            $number = $this->normalizeNumberText($matches[1]);

            return [null, $this->parseNumber($matches[1]), '<='.$number];
        }

        if (preg_match('/^>=\s*([\d]+(?:[.,]\d+)?)$/u', $referencePart, $matches)) {
            $number = $this->normalizeNumberText($matches[1]);

            return [$this->parseNumber($matches[1]), null, '>='.$number];
        }

        if (preg_match('/^<\s*([\d]+(?:[.,]\d+)?)$/u', $referencePart, $matches)) {
            return [null, $this->parseNumber($matches[1]), $referencePart];
        }

        if (preg_match('/^>\s*([\d]+(?:[.,]\d+)?)$/u', $referencePart, $matches)) {
            return [$this->parseNumber($matches[1]), null, $referencePart];
        }

        if (preg_match('/^([\d]+(?:[.,]\d+)?)\s*[-–—]\s*([\d]+(?:[.,]\d+)?)$/u', $referencePart, $matches)) {
            return [$this->parseNumber($matches[1]), $this->parseNumber($matches[2]), $referencePart];
        }

        return [null, null, $referenceText];
    }

    private function compactInequalityReference(string $reference): string
    {
        return preg_replace('/\s+/u', '', str_replace(',', '.', trim($reference))) ?? trim($reference);
    }

    private function normalizeNumberText(string $number): string
    {
        return str_replace(',', '.', trim($number));
    }

    private function parseNumber(string $value): ?float
    {
        $normalized = str_replace(',', '.', trim($value));

        if (! is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
