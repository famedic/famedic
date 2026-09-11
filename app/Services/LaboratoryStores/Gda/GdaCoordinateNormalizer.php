<?php

namespace App\Services\LaboratoryStores\Gda;

class GdaCoordinateNormalizer
{
    public function normalize(null|string|int|float $value, string $axis): NormalizationResult
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return new NormalizationResult(null);
        }

        $axis = strtolower($axis);
        $min = $axis === 'longitude' ? -180 : -90;
        $max = $axis === 'longitude' ? 180 : 90;

        $normalized = $this->canonicalNumber($raw);

        if (is_numeric($normalized) && preg_match('/[.,eE]/', $raw)) {
            $decimal = $this->decimalResult((float) $normalized, $min, $max, $axis);

            if ($decimal->isValid()) {
                return $decimal;
            }

            if (preg_match('/[eE]/', $normalized) === 1) {
                return $decimal;
            }

            $compact = preg_replace('/[^\d-]+/', '', $normalized) ?? $normalized;

            if (preg_match('/^-?\d+$/', $compact) === 1) {
                return $this->compactResult($compact, $axis, $min, $max, 'decimal repositioned from compact coordinate');
            }

            return $decimal;
        }

        if (preg_match('/^-?\d+$/', $normalized) === 1) {
            return $this->compactResult($normalized, $axis, $min, $max, 'decimal inserted from compact coordinate');
        }

        if (is_numeric($normalized)) {
            return $this->decimalResult((float) $normalized, $min, $max, $axis);
        }

        return new NormalizationResult(null, [], ["Invalid {$axis}: {$raw}"]);
    }

    private function compactResult(string $raw, string $axis, int $min, int $max, string $warning): NormalizationResult
    {
        $candidates = $this->integerCandidates($raw, $axis, $min, $max);

        if (count($candidates) === 1) {
            return new NormalizationResult(number_format($candidates[0], 7, '.', ''), [$warning]);
        }

        if ($candidates !== []) {
            return new NormalizationResult(null, ['multiple compact coordinate interpretations'], [], true);
        }

        return new NormalizationResult(null, [], ["{$axis} out of range"]);
    }

    private function canonicalNumber(string $raw): string
    {
        $value = trim($raw);
        $value = trim($value, " \t\n\r\0\x0B,");

        if (preg_match('/^-?\d+,\d{1,7}$/', $value) === 1) {
            return str_replace(',', '.', $value);
        }

        if (preg_match('/^-?\d{1,3}(,\d{3})+(,\d{1,7})?$/', $value) === 1) {
            return str_replace(',', '', $value);
        }

        if (substr_count($value, ',') > 1 && ! str_contains($value, '.')) {
            return preg_replace('/[^\d-]+/', '', $value) ?? $value;
        }

        return str_replace(',', '.', $value);
    }

    private function decimalResult(float $value, int $min, int $max, string $axis): NormalizationResult
    {
        if ($value < $min || $value > $max) {
            return new NormalizationResult(null, [], ["{$axis} out of range"]);
        }

        $warnings = [];

        if ($axis === 'latitude' && ($value < 14 || $value > 33)) {
            $warnings[] = 'latitude outside Mexico reference range';
        }

        if ($axis === 'longitude' && ($value > -86 || $value < -119)) {
            $warnings[] = 'longitude outside Mexico reference range';
        }

        return new NormalizationResult(number_format($value, 7, '.', ''), $warnings);
    }

    private function integerCandidates(string $raw, string $axis, int $min, int $max): array
    {
        $sign = str_starts_with($raw, '-') ? -1 : 1;
        $digits = ltrim($raw, '-');

        if (strlen($digits) > 10) {
            return [];
        }

        $positions = $axis === 'longitude' ? [2, 3] : [1, 2];
        $globalCandidates = [];
        $candidates = [];

        foreach ($positions as $position) {
            if (strlen($digits) <= $position) {
                continue;
            }

            $value = $sign * (float) (substr($digits, 0, $position).'.'.substr($digits, $position));

            if ($value >= $min && $value <= $max) {
                $globalCandidates[] = $value;

                if ($this->isMexicoPlausible($value, $axis)) {
                    $candidates[] = $value;
                }
            }
        }

        $candidates = array_values(array_unique($candidates));

        if ($candidates !== []) {
            return $candidates;
        }

        return count(array_unique($globalCandidates)) > 1 ? $globalCandidates : [];
    }

    private function isMexicoPlausible(float $value, string $axis): bool
    {
        return $axis === 'latitude'
            ? $value >= 14 && $value <= 33
            : $value >= -119 && $value <= -86;
    }
}
