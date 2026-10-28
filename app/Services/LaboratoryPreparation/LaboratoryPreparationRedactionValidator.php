<?php

namespace App\Services\LaboratoryPreparation;

use RuntimeException;

final class LaboratoryPreparationRedactionValidator
{
    public const TITLE = '🧪 RECOMENDACIONES E INSTRUCCIONES DE PREPARACIÓN';

    /**
     * @var array<string, list<string>>
     */
    private const UNIT_VARIANTS = [
        'hora' => ['hora', 'horas', 'h'],
        'dia' => ['dia', 'dias', 'día', 'días'],
        'semana' => ['semana', 'semanas'],
        'mes' => ['mes', 'meses'],
        'litro' => ['l', 'lt', 'litro', 'litros'],
        'mililitro' => ['ml', 'mililitro', 'mililitros'],
        'gramo' => ['g', 'gr', 'gramo', 'gramos'],
        'porciento' => ['%', 'por ciento'],
    ];

    /**
     * @var list<string>
     */
    private const CRITICAL_TERMS = [
        'ayuno',
        'agua',
        'vejiga',
        'orina',
        'frasco',
        'plastico',
        'esteril',
        'vidrio',
        'contenedor',
        'refrigeracion',
        'metformina',
        'medicamento',
        'medicamentos',
        'alcohol',
        'drogas',
        'tabaco',
        'desodorante',
        'crema',
        'cremas',
        'perfume',
        'enema',
        'enemas',
        'microlax',
        'fleet',
        'fosfosoda',
        'dieta',
        'blanda',
        'creatinina',
        'receta',
        'consentimiento',
        'menstruacion',
        'masturbacion',
        'condon',
        'coito',
        'lunes',
        'sabado',
    ];

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validate(array $response, array $payload): array
    {
        foreach (['title', 'mode', 'patient_text', 'bullet_count'] as $key) {
            if (! array_key_exists($key, $response)) {
                throw new RuntimeException("OpenAI redaction response is missing [{$key}].");
            }
        }

        if ($response['title'] !== self::TITLE) {
            throw new RuntimeException('OpenAI redaction response has an invalid title.');
        }

        if ($response['mode'] !== LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED) {
            throw new RuntimeException('OpenAI redaction attempted to change the decision status.');
        }

        $patientText = trim((string) $response['patient_text']);
        if ($patientText === '') {
            throw new RuntimeException('OpenAI redaction patient_text is empty.');
        }

        $inputText = $this->requirementsText($payload);
        $normalizedInput = $this->normalize($inputText);
        $normalizedOutput = $this->normalize($patientText);

        $this->assertNoInventedNumbers($normalizedInput, $normalizedOutput);
        $this->assertNumbersPreserved($normalizedInput, $normalizedOutput);
        $this->assertUnitsPreserved($normalizedInput, $normalizedOutput);
        $this->assertCriticalTermsPreserved($normalizedInput, $normalizedOutput);
        $this->assertRequirementCoverage($payload, $normalizedOutput);
        $this->assertNoDuplicateLines($patientText);

        return [
            'title' => self::TITLE,
            'mode' => LaboratoryPreparationDecision::STATUS_AUTO_CONSOLIDATED,
            'patient_text' => $patientText,
            'bullet_count' => is_numeric($response['bullet_count']) ? (int) $response['bullet_count'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requirementsText(array $payload): string
    {
        return collect($payload['requirements'] ?? [])
            ->map(fn (array $requirement) => (string) ($requirement['text'] ?? ''))
            ->filter(fn (string $text) => trim($text) !== '')
            ->implode("\n");
    }

    private function assertNoInventedNumbers(string $input, string $output): void
    {
        $inputNumbers = $this->numbers($input);
        foreach ($this->numbers($output) as $number) {
            if (! in_array($number, $inputNumbers, true)) {
                throw new RuntimeException("OpenAI redaction invented number [{$number}].");
            }
        }
    }

    private function assertNumbersPreserved(string $input, string $output): void
    {
        foreach ($this->numbers($input) as $number) {
            if (! preg_match('/\b'.preg_quote($number, '/').'\b/u', $output)) {
                throw new RuntimeException("OpenAI redaction omitted number [{$number}].");
            }
        }
    }

    private function assertUnitsPreserved(string $input, string $output): void
    {
        foreach (self::UNIT_VARIANTS as $unit => $variants) {
            if (! $this->containsAnyUnit($input, $variants)) {
                continue;
            }

            if (! $this->containsAnyUnit($output, $variants)) {
                throw new RuntimeException("OpenAI redaction omitted unit [{$unit}].");
            }
        }
    }

    private function assertCriticalTermsPreserved(string $input, string $output): void
    {
        foreach (self::CRITICAL_TERMS as $term) {
            if (! preg_match('/\b'.preg_quote($term, '/').'\b/u', $input)) {
                continue;
            }

            if (! preg_match('/\b'.preg_quote($term, '/').'\b/u', $output)) {
                throw new RuntimeException("OpenAI redaction omitted critical term [{$term}].");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertRequirementCoverage(array $payload, string $output): void
    {
        foreach ($payload['requirements'] ?? [] as $requirement) {
            if (! is_array($requirement)) {
                continue;
            }

            $text = $this->normalize((string) ($requirement['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $missing = [];
            foreach ($this->numbers($text) as $number) {
                if (! preg_match('/\b'.preg_quote($number, '/').'\b/u', $output)) {
                    $missing[] = "number:{$number}";
                }
            }

            foreach (self::CRITICAL_TERMS as $term) {
                if (preg_match('/\b'.preg_quote($term, '/').'\b/u', $text)
                    && ! preg_match('/\b'.preg_quote($term, '/').'\b/u', $output)) {
                    $missing[] = "term:{$term}";
                }
            }

            if ($missing !== []) {
                throw new RuntimeException('OpenAI redaction omitted consolidated requirement ['.implode(', ', $missing).'].');
            }
        }
    }

    private function assertNoDuplicateLines(string $patientText): void
    {
        $seen = [];
        $lines = preg_split('/\R/u', $patientText) ?: [];

        foreach ($lines as $line) {
            $normalized = $this->normalize((string) preg_replace('/^[\s•\-]+/u', '', $line));
            if ($normalized === '' || mb_strlen($normalized) < 12) {
                continue;
            }

            if (isset($seen[$normalized])) {
                throw new RuntimeException('OpenAI redaction repeats an instruction without authorization.');
            }

            $seen[$normalized] = true;
        }
    }

    /**
     * @return list<string>
     */
    private function numbers(string $text): array
    {
        preg_match_all('/\d+(?:[.,]\d+)?/u', $text, $matches);

        return array_values(array_unique(array_map(
            fn (string $number) => str_replace(',', '.', $number),
            $matches[0] ?? [],
        )));
    }

    /**
     * @param  list<string>  $variants
     */
    private function containsAnyUnit(string $text, array $variants): bool
    {
        foreach ($variants as $variant) {
            if (preg_match('/\b'.preg_quote($this->normalize($variant), '/').'\b/u', $text)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ü' => 'u',
            'ñ' => 'n',
        ]);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
