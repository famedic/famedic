<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryResultObservationValueType;

class LaboratoryResultTextParser
{
    private const QUALITATIVE_VALUES = [
        'negativo',
        'positivo',
        'no reactivo',
        'reactivo',
        'no detectado',
        'detectado',
    ];

    /**
     * @return list<LaboratoryResultObservationCandidate>
     */
    public function parse(LaboratoryResultTextExtractionResult $extraction): array
    {
        $candidates = [];

        foreach ($extraction->pages as $page) {
            $pageNumber = (int) $page['page'];
            $rawLines = preg_split('/\R/u', (string) $page['text']) ?: [];
            $lines = array_map(fn (string $line): string => $this->normalizeLine($line), $rawLines);

            foreach ($lines as $index => $line) {
                if ($line === '' || $this->shouldSkipLine($line)) {
                    continue;
                }

                $candidate = $this->parseLine($line, $pageNumber);

                if ($candidate !== null) {
                    $candidates[] = $this->enrichWithGdaCategoricalReference(
                        $candidate,
                        $lines,
                        $index,
                    );

                    continue;
                }

                $multilineCandidate = $this->parseGdaMultilineCategoricalCandidate(
                    $lines,
                    $index,
                    $pageNumber,
                );

                if ($multilineCandidate !== null) {
                    $candidates[] = $multilineCandidate;
                }
            }
        }

        return $candidates;
    }

    private function normalizeLine(string $line): string
    {
        $line = str_replace("\t", ' ', $line);

        return trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
    }

    private function shouldSkipLine(string $line): bool
    {
        $lower = mb_strtolower($line, 'UTF-8');

        if (preg_match('/^(pagina|página|page)\s*\d+/u', $lower)) {
            return true;
        }

        if (preg_match('/^(analito|parametro|parámetro|resultado|unidad|referencia|estudio|paciente|orden|fecha)\b/u', $lower)) {
            return true;
        }

        if (preg_match('/^\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}$/u', $line)) {
            return true;
        }

        if (
            ! $this->looksLikeGdaValueFirstResultLine($line)
            && preg_match('/^\+?\d{10,}$/u', preg_replace('/\D/u', '', $line) ?? '')
        ) {
            return true;
        }

        if (preg_match('/^(tel|telefono|teléfono|curp|rfc)\b/u', $lower)) {
            return true;
        }

        if (preg_match('/\b(cualquier\s+aclaraci|solicitarla\s+como\s+m[aá]ximo|d[ií]as\s+despu[eé]s\s+de\s+la\s+emisi)/ui', $lower)) {
            return true;
        }

        if (preg_match('/^quimica\s+sanguinea\b/ui', $lower)) {
            return true;
        }

        if (preg_match('/^riesgo\s+(bajo|moderado|alto)\b/ui', $lower)) {
            return true;
        }

        if (preg_match('/^(reporte\s+radiolog|ecograf|ultrasonid)\b/ui', $lower)) {
            return true;
        }

        if (preg_match('/^(deseable|lim[ií]trofe|alto|bajo)\s*[>:]/ui', $lower)) {
            return true;
        }

        // Línea sólo valor+unidad sin analito (multi-columna ambigua).
        if (preg_match('/^[\d]+(?:[.,]\d+)?\s+[A-Za-z%µ°\/][A-Za-z0-9%µ°\/\.]{0,20}\s*$/u', $line)) {
            return true;
        }

        return false;
    }

    private function parseLine(string $line, int $pageNumber): ?LaboratoryResultObservationCandidate
    {
        if ($qualitative = $this->parseQualitativeLine($line, $pageNumber)) {
            return $qualitative;
        }

        if ($numeric = $this->parseNumericLine($line, $pageNumber)) {
            return $numeric;
        }

        if ($gdaValueFirst = $this->parseGdaValueFirstLine($line, $pageNumber)) {
            return $gdaValueFirst;
        }

        if ($gdaFlagPrefix = $this->parseGdaFlagPrefixLine($line, $pageNumber)) {
            return $gdaFlagPrefix;
        }

        if ($gdaSuffix = $this->parseGdaSuffixValueLine($line, $pageNumber)) {
            return $gdaSuffix;
        }

        return null;
    }

    /**
     * GDA puede separar categorias de referencia antes de la fila:
     *
     * DESEABLE < 150
     * RIESGO MODERADO 150 - 199
     * ...
     * (A) TRIGLICERIDOS mg/dL63.00
     *
     * Solo se enriquece TG porque es el unico patron validado en corpus 8C-13C.
     *
     * @param  list<string>  $lines
     */
    private function enrichWithGdaCategoricalReference(
        LaboratoryResultObservationCandidate $candidate,
        array $lines,
        int $lineIndex,
    ): LaboratoryResultObservationCandidate {
        if (
            $candidate->referenceText !== null
            || $candidate->numericValue === null
            || ! $this->isTriglyceridesCandidate($candidate->analyteNameRaw)
            || ! $this->isGdaCompatibleUnit($candidate->unit)
        ) {
            return $candidate;
        }

        $referenceText = $this->findNearestPreviousGdaCategoricalReference($lines, $lineIndex);

        if ($referenceText === null) {
            return $candidate;
        }

        [$referenceLow, $referenceHigh, $normalizedReferenceText] = $this->parseReference($referenceText);

        if ($referenceLow === null && $referenceHigh === null) {
            return $candidate;
        }

        return new LaboratoryResultObservationCandidate(
            analyteNameRaw: $candidate->analyteNameRaw,
            valueType: $candidate->valueType,
            numericValue: $candidate->numericValue,
            textValue: $candidate->textValue,
            unit: $candidate->unit,
            unitRaw: $candidate->unitRaw,
            referenceLow: $referenceLow,
            referenceHigh: $referenceHigh,
            referenceText: $normalizedReferenceText,
            sourcePage: $candidate->sourcePage,
            confidence: min($candidate->confidence, 0.84),
            parseRule: 'numeric_gda_categorical_reference_v1',
        );
    }

    /**
     * Soporta un formato conservador cuando Smalot corta una fila en lineas:
     *
     * TRIGLICERIDOS
     * 120
     * mg/dL
     * DESEABLE
     * <150
     *
     * @param  list<string>  $lines
     */
    private function parseGdaMultilineCategoricalCandidate(
        array $lines,
        int $lineIndex,
        int $pageNumber,
    ): ?LaboratoryResultObservationCandidate {
        $name = $lines[$lineIndex] ?? '';

        if (! $this->isTriglyceridesCandidate($name)) {
            return null;
        }

        $value = $lines[$lineIndex + 1] ?? null;
        $unit = $lines[$lineIndex + 2] ?? null;

        if (
            $value === null
            || $unit === null
            || ! preg_match('/^[\d]+(?:[.,]\d+)?$/u', $value)
            || ! $this->isGdaCompatibleUnit($unit)
        ) {
            return null;
        }

        $referenceLines = array_slice($lines, $lineIndex + 3, 3);
        $referenceText = $this->extractGdaCategoricalReferenceFromLines($referenceLines);

        if ($referenceText === null) {
            return null;
        }

        return $this->buildNumericCandidate(
            name: $name,
            numeric: $this->parseNumber($value),
            unit: $unit,
            referencePart: $referenceText,
            pageNumber: $pageNumber,
            parseRule: 'numeric_gda_multiline_categorical_reference_v1',
            confidence: 0.82,
        );
    }

    private function parseQualitativeLine(string $line, int $pageNumber): ?LaboratoryResultObservationCandidate
    {
        foreach (self::QUALITATIVE_VALUES as $qualitative) {
            $pattern = '/^([A-Za-zÁÉÍÓÚáéíóúñ][A-Za-zÁÉÍÓÚáéíóúñ\s\-]{1,60}?)\s+('.preg_quote($qualitative, '/').')\s*(.*)?$/iu';

            if (! preg_match($pattern, $line, $matches)) {
                continue;
            }

            $name = trim($matches[1]);

            if (! $this->looksLikeAnalyteName($name)) {
                continue;
            }

            return new LaboratoryResultObservationCandidate(
                analyteNameRaw: $name,
                valueType: LaboratoryResultObservationValueType::Qualitative,
                textValue: mb_convert_case(trim($matches[2]), MB_CASE_TITLE, 'UTF-8'),
                referenceText: trim($matches[3] ?? '') !== '' ? trim($matches[3]) : null,
                sourcePage: $pageNumber,
                confidence: 0.85,
                parseRule: 'qualitative_v1',
            );
        }

        return null;
    }

    private function parseNumericLine(string $line, int $pageNumber): ?LaboratoryResultObservationCandidate
    {
        if (preg_match(
            '/^([A-Za-zÁÉÍÓÚáéíóúñ][A-Za-zÁÉÍÓÚáéíóúñ\s\-]{1,60}?)\s+([\d]+(?:[.,]\d+)?)\s+([A-Za-z%µ°][A-Za-z0-9%µ°\/\.]{0,20})\s+(.+)$/u',
            $line,
            $matches
        )) {
            return $this->buildNumericCandidate(
                name: trim($matches[1]),
                numeric: $this->parseNumber($matches[2]),
                unit: trim($matches[3]),
                referencePart: trim($matches[4]),
                pageNumber: $pageNumber,
                parseRule: 'numeric_with_unit_v1',
                confidence: 0.92,
            );
        }

        if (preg_match(
            '/^([A-Za-zÁÉÍÓÚáéíóúñ][A-Za-zÁÉÍÓÚáéíóúñ\s\-]{1,60}?)\s+([\d]+(?:[.,]\d+)?)\s+(.+)$/u',
            $line,
            $matches
        )) {
            $name = trim($matches[1]);
            $numeric = $this->parseNumber($matches[2]);
            $referencePart = trim($matches[3]);

            if ($numeric === null || ! $this->looksLikeAnalyteName($name)) {
                return null;
            }

            [$referenceLow, $referenceHigh, $referenceText, $unit] = $this->parseReferenceOnly($referencePart);

            return new LaboratoryResultObservationCandidate(
                analyteNameRaw: $name,
                valueType: LaboratoryResultObservationValueType::Numeric,
                numericValue: $numeric,
                unit: $unit,
                referenceLow: $referenceLow,
                referenceHigh: $referenceHigh,
                referenceText: $referenceText,
                sourcePage: $pageNumber,
                confidence: $unit === null ? 0.65 : 0.9,
                parseRule: 'numeric_no_unit_v1',
            );
        }

        return null;
    }

    /**
     * GDA hemograma / química: valor antes del analito. Ej: 84(A) GLUCOSA mg/dL 70-99
     */
    private function parseGdaValueFirstLine(string $line, int $pageNumber): ?LaboratoryResultObservationCandidate
    {
        if (preg_match('/^([\d]+(?:[.,]\d+)?)\s*(?:\([A-Za-z]\)\s*)?(.+)$/u', $line, $matches)) {
            $parsed = $this->parseGdaNameUnitReferenceTail(trim($matches[2]));

            if ($parsed === null) {
                return null;
            }

            return $this->buildNumericCandidate(
                name: $parsed['name'],
                numeric: $this->parseNumber($matches[1]),
                unit: $parsed['unit'],
                referencePart: $parsed['reference_part'],
                pageNumber: $pageNumber,
                parseRule: 'numeric_gda_value_first_v1',
                confidence: 0.9,
                referenceLow: $parsed['reference_low'],
                referenceHigh: $parsed['reference_high'],
            );
        }

        if (preg_match('/^([\d]+(?:[.,]\d+)?)([A-Za-zÁÉÍÓÚáéíóúñ].+)$/u', $line, $matches)) {
            $parsed = $this->parseGdaNameUnitReferenceTail(trim($matches[2]));

            if ($parsed === null) {
                return null;
            }

            return $this->buildNumericCandidate(
                name: $parsed['name'],
                numeric: $this->parseNumber($matches[1]),
                unit: $parsed['unit'],
                referencePart: $parsed['reference_part'],
                pageNumber: $pageNumber,
                parseRule: 'numeric_gda_value_first_v1',
                confidence: 0.88,
                referenceLow: $parsed['reference_low'],
                referenceHigh: $parsed['reference_high'],
            );
        }

        return null;
    }

    /**
     * GDA química: flag (A) antes del analito. Ej: (A) GLUCOSA mg/dL 60-10093
     */
    private function parseGdaFlagPrefixLine(string $line, int $pageNumber): ?LaboratoryResultObservationCandidate
    {
        if (! preg_match('/^\([A-Za-z]\)\s+(.+)$/u', $line, $prefixMatch)) {
            return null;
        }

        $rest = trim($prefixMatch[1]);

        if (preg_match(
            '/^(.+?)\s+([A-Za-z%µ°\/][A-Za-z%µ°\/\.]+)(\d+(?:[.,]\d+)?)$/u',
            $rest,
            $matches
        )) {
            return $this->buildNumericCandidate(
                name: trim($matches[1]),
                numeric: $this->parseNumber($matches[3]),
                unit: trim($matches[2]),
                referencePart: null,
                pageNumber: $pageNumber,
                parseRule: 'numeric_gda_flag_fused_unit_v1',
                confidence: 0.86,
            );
        }

        $parsed = $this->parseGdaNameUnitReferenceTail($rest);

        if ($parsed === null || $parsed['fused_value'] === null) {
            return null;
        }

        return $this->buildNumericCandidate(
            name: $parsed['name'],
            numeric: $parsed['fused_value'],
            unit: $parsed['unit'],
            referencePart: $parsed['reference_part'],
            pageNumber: $pageNumber,
            parseRule: 'numeric_gda_flag_fused_ref_v1',
            confidence: 0.87,
            referenceLow: $parsed['reference_low'],
            referenceHigh: $parsed['reference_high'],
        );
    }

    /**
     * Líneas con prefijo de referencia/categoría antes del flag. Ej: >?40(A) LIPOPROTEINA... mg/dL68.00
     */
    private function parseGdaSuffixValueLine(string $line, int $pageNumber): ?LaboratoryResultObservationCandidate
    {
        if (! preg_match('/\([A-Za-z]\)\s+([A-Za-zÁÉÍÓÚáéíóúñ].+)$/u', $line, $tailMatch)) {
            return null;
        }

        $rest = trim($tailMatch[1]);

        if (preg_match(
            '/^(.+?)\s+([A-Za-z%µ°\/][A-Za-z%µ°\/\.]+)(\d+(?:[.,]\d+)?)$/u',
            $rest,
            $matches
        )) {
            return $this->buildNumericCandidate(
                name: trim($matches[1]),
                numeric: $this->parseNumber($matches[3]),
                unit: trim($matches[2]),
                referencePart: null,
                pageNumber: $pageNumber,
                parseRule: 'numeric_gda_suffix_fused_unit_v1',
                confidence: 0.85,
            );
        }

        return null;
    }

    /**
     * Parsea cola GDA desde la derecha: analito + unidad + referencia[/valor fusionado].
     *
     * @return array{
     *     name: string,
     *     unit: string,
     *     reference_part: ?string,
     *     reference_low: ?float,
     *     reference_high: ?float,
     *     fused_value: ?float
     * }|null
     */
    private function parseGdaNameUnitReferenceTail(string $rest): ?array
    {
        if (preg_match(
            '/^(.+)\s+([A-Za-z%µ°\/][A-Za-z0-9%µ°\/\.]{0,20})\s+(.+)$/u',
            $rest,
            $matches
        )) {
            $name = trim($matches[1]);
            $unit = trim($matches[2]);
            $referencePart = trim($matches[3]);

            if ($this->isGdaReferenceCategoryToken($unit)) {
                $referencePart = trim($unit.' '.$referencePart);
                $unit = null;
            }

            [$referenceLow, $referenceHigh, $referenceText, $fusedValue] = $this->parseReferenceWithOptionalFusedValue($referencePart);

            if (! $this->looksLikeAnalyteName($name)) {
                return null;
            }

            return [
                'name' => $name,
                'unit' => $unit,
                'reference_part' => $referenceText ?? $referencePart,
                'reference_low' => $referenceLow,
                'reference_high' => $referenceHigh,
                'fused_value' => $fusedValue,
            ];
        }

        if (preg_match(
            '/^(.+?)\s+([A-Za-z%µ°\/][A-Za-z%µ°\/\.]+)(\d+(?:[.,]\d+)?)$/u',
            $rest,
            $matches
        )) {
            $name = trim($matches[1]);
            $unit = trim($matches[2]);

            if (! $this->looksLikeAnalyteName($name)) {
                return null;
            }

            return [
                'name' => $name,
                'unit' => $unit,
                'reference_part' => null,
                'reference_low' => null,
                'reference_high' => null,
                'fused_value' => $this->parseNumber($matches[3]),
            ];
        }

        return null;
    }

    private function buildNumericCandidate(
        string $name,
        ?float $numeric,
        ?string $unit,
        ?string $referencePart,
        int $pageNumber,
        string $parseRule,
        float $confidence,
        ?float $referenceLow = null,
        ?float $referenceHigh = null,
    ): ?LaboratoryResultObservationCandidate {
        if ($numeric === null || ! $this->looksLikeAnalyteName($name)) {
            return null;
        }

        if ($referenceLow === null && $referenceHigh === null && $referencePart !== null && $referencePart !== '') {
            [$referenceLow, $referenceHigh, $referenceText] = $this->parseReference($referencePart);
        } else {
            $referenceText = $referencePart;
        }

        return new LaboratoryResultObservationCandidate(
            analyteNameRaw: $name,
            valueType: LaboratoryResultObservationValueType::Numeric,
            numericValue: $numeric,
            unit: $unit,
            unitRaw: $unit,
            referenceLow: $referenceLow,
            referenceHigh: $referenceHigh,
            referenceText: $referenceText ?? $referencePart,
            sourcePage: $pageNumber,
            confidence: $confidence,
            parseRule: $parseRule,
        );
    }

    /**
     * @return array{0: ?float, 1: ?float, 2: ?string, 3: ?float}
     */
    private function parseReferenceWithOptionalFusedValue(string $referencePart): array
    {
        $referencePart = trim($referencePart);

        if (! preg_match('/^([\d]+(?:[.,]\d+)?)\s*[-–—]\s*(.+)$/u', $referencePart, $rangeMatch)) {
            [$low, $high, $text] = $this->parseReference($referencePart);

            return [$low, $high, $text, null];
        }

        $lowStr = str_replace(',', '.', trim($rangeMatch[1]));
        $low = $this->parseNumber($lowStr);
        $tail = str_replace(',', '.', trim($rangeMatch[2]));

        if ($this->isSimpleReferenceHighToken($tail)) {
            $high = $this->parseNumber($tail);

            return [$low, $high, $lowStr.'-'.$tail, null];
        }

        $decimalSplit = $this->splitGdaFusedDecimalTail($tail);

        if ($decimalSplit !== null) {
            [$highStr, $high, $fusedValue] = $decimalSplit;

            return [$low, $high, $lowStr.'-'.$highStr, $fusedValue];
        }

        $integerSplit = $this->splitConcatenatedHighAndValue($tail);

        if ($integerSplit !== null) {
            [$highStr, , $high, $fusedValue] = $integerSplit;

            return [$low, $high, $lowStr.'-'.$highStr, $fusedValue];
        }

        [$low, $high, $text] = $this->parseReference($referencePart);

        return [$low, $high, $text, null];
    }

    /**
     * Token de reference_high sin valor fusionado (p. ej. 1.10, 6.00, 100).
     * Enteros largos sin punto (p. ej. 10093) no califican — requieren split entero.
     */
    private function isSimpleReferenceHighToken(string $tail): bool
    {
        if ($tail === '' || substr_count($tail, '.') >= 2) {
            return false;
        }

        if (substr_count($tail, '.') === 1) {
            return (bool) preg_match('/^\d+\.\d+$/u', $tail);
        }

        return (bool) preg_match('/^\d{1,3}$/u', $tail);
    }

    /**
     * Separa reference_high + value cuando Smalot fusiona decimales (p. ej. 1.020.8, 6.003.9).
     *
     * @return array{0: string, 1: float, 2: float}|null
     */
    private function splitGdaFusedDecimalTail(string $tail): ?array
    {
        if (substr_count($tail, '.') < 2) {
            return null;
        }

        $length = strlen($tail);
        $best = null;

        for ($splitAt = 1; $splitAt < $length; $splitAt++) {
            $highStr = substr($tail, 0, $splitAt);
            $valueStr = substr($tail, $splitAt);

            if (
                ! $this->looksLikeDecimalToken($highStr)
                || ! $this->looksLikeDecimalToken($valueStr)
                || str_starts_with($valueStr, '.')
            ) {
                continue;
            }

            $high = $this->parseNumber($highStr);
            $fusedValue = $this->parseNumber($valueStr);

            if (
                $high === null
                || $fusedValue === null
                || $fusedValue <= 0
                || $high <= 0
                || abs($fusedValue - $high) <= 0.0001
            ) {
                continue;
            }

            $ratio = $fusedValue / $high;

            if ($ratio < 0.05 || $ratio > 5.0) {
                continue;
            }

            $score = $this->scoreGdaFusedSplit($highStr, $valueStr, $fusedValue);

            if ($best === null || $score > $best['score']) {
                $best = [
                    'highStr' => $highStr,
                    'high' => $high,
                    'fusedValue' => $fusedValue,
                    'score' => $score,
                ];
            }
        }

        if ($best === null) {
            return null;
        }

        return [$best['highStr'], $best['high'], $best['fusedValue']];
    }

    private function looksLikeDecimalToken(string $token): bool
    {
        return (bool) preg_match('/^\d+(?:\.\d+)?$/u', $token);
    }

    private function scoreGdaFusedSplit(string $highStr, string $valueStr, float $fusedValue): int
    {
        $score = 0;

        if (str_contains($valueStr, '.')) {
            $score += 4;
        }

        if (preg_match('/^\d+\.\d{2}$/u', $highStr)) {
            $score += 3;
        } elseif (preg_match('/^\d+\.\d{1}$/u', $highStr)) {
            $score += 1;
        }

        if ($fusedValue < 10.0) {
            $score += 2;
        }

        if (strlen($valueStr) <= 3) {
            $score += 1;
        }

        if (preg_match('/^\d+\.\d{3,}$/u', $highStr) && ! str_contains($valueStr, '.')) {
            $score -= 3;
        }

        return $score;
    }

    /**
     * @return array{0: ?float, 1: ?float, 2: ?string}
     */
    private function parseReference(string $referencePart): array
    {
        $referenceText = trim($referencePart);

        if (preg_match('/^(?:deseable|normal|bajo|alto|lim[ií]trofe)\s+([<>]=?\s*[\d]+(?:[.,]\d+)?)$/ui', $referenceText, $matches)) {
            $referenceText = $this->compactInequalityReference($matches[1]);
        }

        if (preg_match('/^<=\s*([\d]+(?:[.,]\d+)?)$/u', $referenceText, $matches)) {
            return [null, $this->parseNumber($matches[1]), '<='.$this->normalizeNumberText($matches[1])];
        }

        if (preg_match('/^>=\s*([\d]+(?:[.,]\d+)?)$/u', $referenceText, $matches)) {
            return [$this->parseNumber($matches[1]), null, '>='.$this->normalizeNumberText($matches[1])];
        }

        if (preg_match('/^<\s*([\d]+(?:[.,]\d+)?)$/u', $referenceText, $matches)) {
            return [null, $this->parseNumber($matches[1]), $referenceText];
        }

        if (preg_match('/^>\s*([\d]+(?:[.,]\d+)?)$/u', $referenceText, $matches)) {
            return [$this->parseNumber($matches[1]), null, $referenceText];
        }

        if (preg_match('/^([\d]+(?:[.,]\d+)?)\s*[-–—]\s*([\d]+(?:[.,]\d+)?)$/u', $referenceText, $matches)) {
            return [$this->parseNumber($matches[1]), $this->parseNumber($matches[2]), $referenceText];
        }

        if (preg_match('/^(mayor|menor)\s+de\s+([\d]+(?:[.,]\d+)?)$/ui', $referenceText, $matches)) {
            return [null, null, $referenceText];
        }

        return [null, null, $referenceText === '' ? null : $referenceText];
    }

    /**
     * @return array{0: ?float, 1: ?float, 2: ?string, 3: ?string}
     */
    private function parseReferenceOnly(string $referencePart): array
    {
        if (preg_match('/^([A-Za-z%µ°][A-Za-z0-9%µ°\/\.]{0,20})\s+(.+)$/u', $referencePart, $matches)) {
            [$low, $high, $text] = $this->parseReference(trim($matches[2]));

            return [$low, $high, $text, trim($matches[1])];
        }

        [$low, $high, $text] = $this->parseReference($referencePart);

        return [$low, $high, $text, null];
    }

    /**
     * @return array{0: string, 1: string, 2: float, 3: float}|null
     */
    private function splitConcatenatedHighAndValue(string $remainder): ?array
    {
        $remainder = str_replace(',', '.', trim($remainder));

        if ($this->isSimpleReferenceHighToken($remainder)) {
            return null;
        }

        $length = strlen($remainder);

        $best = null;

        for ($splitAt = 1; $splitAt < $length; $splitAt++) {
            $highStr = substr($remainder, 0, $splitAt);
            $valueStr = substr($remainder, $splitAt);

            if (
                str_starts_with($valueStr, '.')
                || ! preg_match('/^[\d.]+$/u', $highStr)
                || ! preg_match('/^[\d.]+$/u', $valueStr)
            ) {
                continue;
            }

            $high = $this->parseNumber($highStr);
            $fusedValue = $this->parseNumber($valueStr);

            if (
                $high === null
                || $fusedValue === null
                || $fusedValue <= 0
                || abs($fusedValue - $high) <= 0.0001
                || $high <= 0
            ) {
                continue;
            }

            $ratio = $fusedValue / $high;

            if ($ratio < 0.05 || $ratio > 5.0) {
                continue;
            }

            if ($best === null || strlen($highStr) >= strlen($best[0])) {
                $best = [$highStr, $valueStr, $high, $fusedValue];
            }
        }

        return $best;
    }

    private function parseNumber(string $value): ?float
    {
        $normalized = str_replace(',', '.', trim($value));

        if (! is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    /**
     * @param  list<string>  $lines
     */
    private function findNearestPreviousGdaCategoricalReference(array $lines, int $lineIndex): ?string
    {
        $windowStart = max(0, $lineIndex - 8);
        $context = [];

        for ($index = $lineIndex - 1; $index >= $windowStart; $index--) {
            $line = $lines[$index] ?? '';

            if ($line === '') {
                continue;
            }

            if ($this->parseLine($line, 1) !== null) {
                break;
            }

            array_unshift($context, $line);
        }

        return $this->extractGdaCategoricalReferenceFromLines($context);
    }

    /**
     * @param  list<string>  $lines
     */
    private function extractGdaCategoricalReferenceFromLines(array $lines): ?string
    {
        $count = count($lines);

        for ($index = 0; $index < $count; $index++) {
            $line = trim($lines[$index]);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^(?:deseable|normal|bajo|alto|lim[ií]trofe)\s+([<>]=?\s*[\d]+(?:[.,]\d+)?)$/ui', $line, $matches)) {
                return $this->compactInequalityReference($matches[1]);
            }

            if (
                preg_match('/^(?:deseable|normal|bajo|alto|lim[ií]trofe)$/ui', $line)
                && isset($lines[$index + 1])
                && preg_match('/^([<>]=?\s*[\d]+(?:[.,]\d+)?)$/u', trim($lines[$index + 1]), $matches)
            ) {
                return $this->compactInequalityReference($matches[1]);
            }
        }

        return null;
    }

    private function compactInequalityReference(string $reference): string
    {
        return preg_replace('/\s+/u', '', str_replace(',', '.', trim($reference))) ?? trim($reference);
    }

    private function normalizeNumberText(string $number): string
    {
        return str_replace(',', '.', trim($number));
    }

    private function isTriglyceridesCandidate(string $name): bool
    {
        $normalized = LaboratoryAnalyteNameNormalizer::normalize($name);

        return $normalized === 'trigliceridos' || str_contains($normalized, 'triglicerido');
    }

    private function looksLikeGdaValueFirstResultLine(string $line): bool
    {
        if (! preg_match('/^[\d]+(?:[.,]\d+)?\s*\([A-Za-z]\)\s+.+/u', $line)) {
            return false;
        }

        if (preg_match(
            '/\b(mg\/dL|g\/dL|mmol\/L|U\/L|mEq\/L|mg\/dl|g\/dl|mmol\/l|u\/l)\b/ui',
            $line,
        )) {
            return true;
        }

        if (preg_match(
            '/[\d]+(?:[.,]\d+)?\s*[-–—]\s*[\d]+(?:[.,]\d+)?|<\s*[\d]+(?:[.,]\d+)?|>\s*[\d]+(?:[.,]\d+)?/u',
            $line,
        )) {
            return true;
        }

        return preg_match('/\b(DESEABLE|LIMITROFE|LIM[IÍ]TROFE|RIESGO)\b/ui', $line) === 1;
    }

    private function isGdaReferenceCategoryToken(string $token): bool
    {
        $normalized = mb_strtolower(trim($token), 'UTF-8');

        return preg_match(
            '/^(deseable|lim[ií]trofe|limitrofe|alto|bajo|riesgo)$/u',
            $normalized,
        ) === 1;
    }

    private function isGdaCompatibleUnit(?string $unit): bool
    {
        if ($unit === null) {
            return false;
        }

        return mb_strtolower(str_replace(' ', '', $unit), 'UTF-8') === 'mg/dl';
    }

    private function looksLikeAnalyteName(string $name): bool
    {
        if (mb_strlen($name, 'UTF-8') < 2) {
            return false;
        }

        if (! preg_match('/\p{L}/u', $name)) {
            return false;
        }

        return ! $this->isRejectedAnalyteName($name);
    }

    private function isRejectedAnalyteName(string $name): bool
    {
        $normalized = mb_strtolower(trim($name), 'UTF-8');

        if (preg_match('/^(quimica\s+sanguinea|elementos|resultado\s+final|unidades|estudio)$/u', $normalized)) {
            return true;
        }

        if (preg_match('/^(riesgo|mayor\s+de|menor\s+de|un\s+resultado|cualquier\s+aclaraci|deseable|lim[ií]trofe)/u', $normalized)) {
            return true;
        }

        if (preg_match('/solicitarla\s+como/u', $normalized)) {
            return true;
        }

        if (preg_match('/^riesgo\s+(bajo|moderado|alto)/u', $normalized)) {
            return true;
        }

        $normalizedAnalyte = LaboratoryAnalyteNameNormalizer::normalize($name);

        if (preg_match('/^rinon\s+(derecho|izquierdo)\b/u', $normalizedAnalyte)) {
            return true;
        }

        if (preg_match('/\b(ecograf|radiolog|hepatomeg|esplenomeg|vesicula\s+biliar)\b/u', $normalized)) {
            return true;
        }

        return mb_strlen($normalized, 'UTF-8') > 80;
    }
}
