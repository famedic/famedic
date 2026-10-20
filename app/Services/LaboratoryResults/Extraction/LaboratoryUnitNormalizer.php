<?php

namespace App\Services\LaboratoryResults\Extraction;

/**
 * Equivalencias de representación de unidades — NO convierte valores numéricos.
 */
final class LaboratoryUnitNormalizer
{
    /**
     * Clave de equivalencia determinista. Unidades desconocidas usan prefijo raw:.
     */
    public function equivalenceKey(?string $unit): ?string
    {
        $normalized = $this->normalizeSymbol($unit);

        if ($normalized === null) {
            return null;
        }

        if (in_array($normalized, ['%', 'percent', 'porciento'], true)) {
            return 'percent';
        }

        if ($normalized === 'pg') {
            return 'pg';
        }

        if ($normalized === 'g/dl') {
            return 'g_per_dl';
        }

        if ($normalized === 'mg/dl') {
            return 'mg_per_dl';
        }

        if ($normalized === 'fl') {
            return 'fl';
        }

        if ($this->isPerMicroliter1e6($normalized)) {
            return '1e6_per_ul';
        }

        if ($this->isPerMicroliter1e3($normalized)) {
            return '1e3_per_ul';
        }

        if ($normalized === 'ul') {
            return 'ul';
        }

        return 'raw:'.$normalized;
    }

    public function areEquivalent(?string $left, ?string $right): bool
    {
        if ($left === null && $right === null) {
            return true;
        }

        if ($left === null || $right === null) {
            return false;
        }

        $leftKey = $this->equivalenceKey($left);
        $rightKey = $this->equivalenceKey($right);

        if ($leftKey === null || $rightKey === null) {
            return false;
        }

        if (str_starts_with($leftKey, 'raw:') || str_starts_with($rightKey, 'raw:')) {
            return $leftKey === $rightKey;
        }

        return $leftKey === $rightKey;
    }

    /**
     * Unidades incompatibles para comparación clínica segura (ej. pg vs g/dL).
     */
    public function areCompatible(?string $left, ?string $right): bool
    {
        return $this->areEquivalent($left, $right);
    }

    public function normalizedSymbol(?string $unit): ?string
    {
        return $this->normalizeSymbol($unit);
    }

    private function normalizeSymbol(?string $unit): ?string
    {
        if ($unit === null) {
            return null;
        }

        $value = mb_strtolower(trim($unit), 'UTF-8');
        $value = str_replace(['µ', 'μ'], 'u', $value);
        $value = str_replace([' ', "\t"], '', $value);
        $value = str_replace('×', 'x', $value);
        $value = preg_replace('/10\s*\^\s*6/u', '10^6', $value) ?? $value;
        $value = preg_replace('/10\s*\^\s*3/u', '10^3', $value) ?? $value;
        $value = preg_replace('/x10\s*\^?\s*6/u', '10^6', $value) ?? $value;
        $value = preg_replace('/x10\s*\^?\s*3/u', '10^3', $value) ?? $value;
        $value = str_replace('10e6', '10^6', $value);
        $value = str_replace('10e3', '10^3', $value);

        return $value === '' ? null : $value;
    }

    /**
     * mill/mm3 (GDA eritrocitos) ≡ mill/µL: 1 mm³ = 1 µL — equivalencia de
     * representación, sin conversión numérica del valor.
     */
    private function isPerMicroliter1e6(string $normalized): bool
    {
        $patterns = [
            '10^6/ul',
            '10^6/u',
            'mill/ul',
            'mill/mm3',
            'millones/ul',
            'millones/u',
            'mill/u',
        ];

        return in_array($normalized, $patterns, true);
    }

    private function isPerMicroliter1e3(string $normalized): bool
    {
        $patterns = [
            '10^3/ul',
            '10^3/u',
            'miles/ul',
            'miles/u',
            'mil/ul',
        ];

        return in_array($normalized, $patterns, true);
    }
}
