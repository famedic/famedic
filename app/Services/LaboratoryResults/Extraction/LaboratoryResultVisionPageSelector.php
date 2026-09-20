<?php

namespace App\Services\LaboratoryResults\Extraction;

class LaboratoryResultVisionPageSelector
{
    private const RESULT_KEYWORDS = [
        'glucosa', 'hemoglobina', 'urea', 'creatinina', 'resultado', 'analito',
        'parametro', 'parámetro', 'referencia', 'unidad', 'mg/dl', 'g/dl',
    ];

    /**
     * @return list<array{page: int, text: string, char_count: int, score: float}>
     */
    public function select(LaboratoryResultTextExtractionResult $extraction): array
    {
        $maxPages = max(1, (int) config('laboratory-results.vision_extraction.max_pages', 3));

        $scored = [];

        foreach ($extraction->pages as $page) {
            $text = (string) ($page['text'] ?? '');
            $redacted = $this->redactSensitiveLines($text);
            $score = $this->scorePage($redacted);

            if ($score <= 0 && trim($redacted) === '') {
                continue;
            }

            $scored[] = [
                'page' => (int) $page['page'],
                'text' => $redacted,
                'char_count' => mb_strlen($redacted, 'UTF-8'),
                'score' => $score,
            ];
        }

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        if ($scored === []) {
            foreach ($extraction->pages as $page) {
                $redacted = $this->redactSensitiveLines((string) ($page['text'] ?? ''));

                if (trim($redacted) === '') {
                    continue;
                }

                $scored[] = [
                    'page' => (int) $page['page'],
                    'text' => $redacted,
                    'char_count' => mb_strlen($redacted, 'UTF-8'),
                    'score' => 0.1,
                ];
            }
        }

        return array_slice($scored, 0, $maxPages);
    }

    private function scorePage(string $text): float
    {
        if (trim($text) === '') {
            return 0.0;
        }

        $lower = mb_strtolower($text, 'UTF-8');
        $score = 0.0;

        foreach (self::RESULT_KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                $score += 1.0;
            }
        }

        if (preg_match('/\d+(?:[.,]\d+)?\s+[a-z%µ°\/\.]{1,20}/iu', $text)) {
            $score += 2.0;
        }

        return $score;
    }

    /**
     * Redacta líneas con PII conocida antes de enviar a Vision.
     *
     * RIESGO: si PII aparece embebida en imágenes sin ser línea separada, no se redacta en esta fase.
     */
    public function redactSensitiveLines(string $text): string
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $filtered = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            $lower = mb_strtolower($trimmed, 'UTF-8');

            if (preg_match('/\b(paciente|nombre|curp|rfc|tel|telefono|teléfono|correo|email|direccion|dirección)\b/u', $lower)) {
                continue;
            }

            if (preg_match('/^[A-ZÁÉÍÓÚÑ]{4}\d{6}[A-Z0-9]{8}$/u', preg_replace('/\s/u', '', $trimmed) ?? '')) {
                continue;
            }

            if (preg_match('/^\+?\d{10,}$/u', preg_replace('/\D/u', '', $trimmed) ?? '')) {
                continue;
            }

            $filtered[] = $trimmed;
        }

        return implode("\n", $filtered);
    }
}
