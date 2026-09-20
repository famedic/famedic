<?php

namespace App\Services\LaboratoryResults\Extraction;

/**
 * Detección determinista de PII en tokens/líneas de PDF (sin OpenAI ni BD de pacientes).
 */
class LaboratoryResultVisionPiiPatternMatcher
{
    /** @var list<string> */
    private const PII_LABELS = [
        'paciente',
        'nombre del paciente',
        'nombre paciente',
        'fecha de nacimiento',
        'fecha nacimiento',
        'curp',
        'rfc',
        'telefono',
        'teléfono',
        'correo electronico',
        'correo electrónico',
        'direccion',
        'dirección',
        'domicilio',
        'expediente',
    ];

    public function textContainsPiiLabel(string $text): bool
    {
        $lower = mb_strtolower(trim($text), 'UTF-8');

        foreach (self::PII_LABELS as $label) {
            if (str_contains($lower, $label)) {
                return true;
            }
        }

        return false;
    }

    public function textMatchesPiiValue(string $text): bool
    {
        $normalized = trim($text);

        if ($normalized === '') {
            return false;
        }

        $compact = preg_replace('/\s/u', '', $normalized) ?? $normalized;

        if (preg_match('/^[A-ZÁÉÍÓÚÑ]{4}\d{6}[A-Z0-9]{8}$/u', $compact)) {
            return true;
        }

        if (preg_match('/@[\w.-]+\.[A-Za-z]{2,}/u', $normalized)) {
            return true;
        }

        if (! str_contains($normalized, '.') && ! str_contains($normalized, ',')) {
            $digits = preg_replace('/\D/u', '', $normalized) ?? '';

            if (strlen($digits) >= 10 && strlen($digits) <= 15) {
                return true;
            }
        }

        if (preg_match('/\b\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}\b/u', $normalized)) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<array{text: string, top: float, left: float, width: float, height: float}>  $words
     * @return list<array{text: string, top: float, left: float, width: float, height: float, reason: string}>
     */
    public function findPiiWords(array $words, float $minTopPt = 0.0): array
    {
        $groupedByLine = [];

        foreach ($words as $word) {
            if ($word['top'] < $minTopPt) {
                continue;
            }

            $lineKey = (int) round($word['top']);

            $groupedByLine[$lineKey][] = $word;
        }

        $matches = [];

        foreach ($groupedByLine as $lineWords) {
            usort($lineWords, fn (array $a, array $b): int => $a['left'] <=> $b['left']);
            $lineText = implode(' ', array_map(fn (array $w): string => $w['text'], $lineWords));

            if ($this->textContainsPiiLabel($lineText)) {
                foreach ($lineWords as $word) {
                    $matches[] = array_merge($word, ['reason' => 'pii_label_line']);
                }

                continue;
            }

            foreach ($lineWords as $word) {
                if ($this->textMatchesPiiValue($word['text'])) {
                    $matches[] = array_merge($word, ['reason' => 'pii_value_token']);
                }
            }
        }

        return $matches;
    }
}
