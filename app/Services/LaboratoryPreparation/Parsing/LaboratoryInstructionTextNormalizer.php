<?php

namespace App\Services\LaboratoryPreparation\Parsing;

final class LaboratoryInstructionTextNormalizer
{
    /**
     * Normaliza acentos sin alterar longitud ni espacios (para alinear offsets con el texto original).
     */
    public function normalizeAccentsOnly(string $value): string
    {
        return str_replace(
            ['Á', 'É', 'Í', 'Ó', 'Ú', 'Ü', 'Ñ', 'á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['A', 'E', 'I', 'O', 'U', 'U', 'N', 'a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $value,
        );
    }

    /**
     * Normalización segura solo para detección (no reemplaza el texto original persistido).
     */
    public function normalizeForDetection(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $normalized,
        );
        $normalized = str_replace(['–', '—', '−'], '-', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s*-\s*/u', ' - ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * @return list<string>
     */
    public function splitClauses(string $indications): array
    {
        $lines = preg_split('/\R/u', $indications) ?: [];
        $clauses = [];

        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/^[-•*]\s*/u', '', trim($line)));
            if ($line !== '') {
                $clauses[] = $line;
            }
        }

        if ($clauses === [] && trim($indications) !== '') {
            $clauses[] = trim($indications);
        }

        return $clauses;
    }
}
