<?php

namespace App\Services\LaboratoryResults\AiExplanation\Contract;

final class LaboratoryResultAiExplanationOutputValidator
{
    /** @return array{explanation: string, limitations: string} */
    public function validate(array $output, array $input): array
    {
        $this->assertAllowedKeysOnly($output);
        $this->assertRequiredString($output, 'explanation', LaboratoryResultAiExplanationContract::MAX_EXPLANATION_LENGTH);
        $this->assertRequiredString($output, 'limitations', LaboratoryResultAiExplanationContract::MAX_LIMITATIONS_LENGTH);
        $this->assertDoesNotModifyInput($output);
        $this->assertDoesNotContradictInput($output['explanation'], $output['limitations'], $input);
        $this->assertSafetyPatterns($output['explanation']);
        $this->assertSafetyPatterns($output['limitations']);
        $this->assertNoPiiPatterns($output['explanation']);
        $this->assertNoPiiPatterns($output['limitations']);

        return [
            'explanation' => trim($output['explanation']),
            'limitations' => trim($output['limitations']),
        ];
    }

    /** @param array<string, mixed> $output */
    private function assertAllowedKeysOnly(array $output): void
    {
        $extra = array_diff(array_keys($output), LaboratoryResultAiExplanationContract::OUTPUT_ALLOWED_KEYS);
        if ($extra !== []) {
            throw LaboratoryResultAiExplanationValidationException::invalidStructure('unexpected fields: '.implode(', ', $extra));
        }

        $missing = array_diff(LaboratoryResultAiExplanationContract::OUTPUT_ALLOWED_KEYS, array_keys($output));
        if ($missing !== []) {
            throw LaboratoryResultAiExplanationValidationException::invalidStructure('missing fields: '.implode(', ', $missing));
        }
    }

    /** @param array<string, mixed> $output */
    private function assertRequiredString(array $output, string $key, int $maxLength): void
    {
        if (! isset($output[$key]) || ! is_string($output[$key]) || trim($output[$key]) === '') {
            throw LaboratoryResultAiExplanationValidationException::invalidStructure("{$key} must be a non-empty string");
        }

        if (mb_strlen(trim($output[$key])) > $maxLength) {
            throw LaboratoryResultAiExplanationValidationException::invalidStructure("{$key} exceeds max length");
        }
    }

    /** @param array<string, mixed> $output */
    private function assertDoesNotModifyInput(array $output): void
    {
        foreach (['status', 'abnormal', 'reference_status', 'abnormal_flag', 'value', 'unit'] as $key) {
            if (array_key_exists($key, $output)) {
                throw LaboratoryResultAiExplanationValidationException::invalidStructure("output must not contain clinical field {$key}");
            }
        }
    }

    private function assertDoesNotContradictInput(string $explanation, string $limitations, array $input): void
    {
        $text = $explanation.' '.$limitations;
        $status = (string) data_get($input, 'status', '');

        $this->assertStatusConsistency($text, $status);

        $value = data_get($input, 'result.value');
        if (is_numeric($value)) {
            $this->assertNumericValueConsistency($text, (float) $value, $input);
        }

        $unit = data_get($input, 'result.unit');
        if (is_string($unit) && trim($unit) !== '') {
            $this->assertUnitConsistency($text, trim($unit), $value);
        }
    }

    private function assertStatusConsistency(string $text, string $status): void
    {
        $patterns = match ($status) {
            'high' => [
                '/\bdentro del rango normal\b/iu',
                '/\bes un resultado normal\b/iu',
                '/\best[aá] dentro del rango normal\b/iu',
                '/\bdentro de lo normal\b/iu',
            ],
            'low' => [
                '/\bdentro del rango normal\b/iu',
                '/\bes un resultado normal\b/iu',
                '/\best[aá] dentro del rango normal\b/iu',
            ],
            'normal' => [
                '/\bpor encima del rango\b/iu',
                '/\bpor debajo del rango\b/iu',
                '/\bfuera del rango\b/iu',
                '/\best[aá] (?:alto|elevado)\b/iu',
                '/\best[aá] bajo\b/iu',
            ],
            default => [],
        };

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                throw LaboratoryResultAiExplanationValidationException::invalidStructure('output contradicts input reference status');
            }
        }
    }

    /** @param array<string, mixed> $input */
    private function assertNumericValueConsistency(string $text, float $expectedValue, array $input): void
    {
        $allowed = [$expectedValue];
        if (is_numeric(data_get($input, 'reference.low'))) {
            $allowed[] = (float) data_get($input, 'reference.low');
        }
        if (is_numeric(data_get($input, 'reference.high'))) {
            $allowed[] = (float) data_get($input, 'reference.high');
        }

        $unit = (string) data_get($input, 'result.unit', '');
        $unitPattern = $unit !== '' ? preg_quote($unit, '/') : '[a-zA-Z/%µ]+';

        $patterns = [
            '/\bel resultado es\s+(\d+(?:[.,]\d+)?)/iu',
            '/\bvalor (?:de|del resultado)?\s*:?\s*(\d+(?:[.,]\d+)?)/iu',
            '/\bmide\s+(\d+(?:[.,]\d+)?)/iu',
            '/\bes de\s+(\d+(?:[.,]\d+)?)\s*(?:'.$unitPattern.')/iu',
            '/\b(?:valor|resultado) reportado es\s+(\d+(?:[.,]\d+)?)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $text, $matches)) {
                continue;
            }

            $found = (float) str_replace(',', '.', $matches[1]);
            if (! $this->isAllowedNumericReference($found, $allowed)) {
                throw LaboratoryResultAiExplanationValidationException::invalidStructure('output contradicts input result value');
            }
        }
    }

    private function assertUnitConsistency(string $text, string $expectedUnit, mixed $value): void
    {
        foreach (LaboratoryResultAiExplanationContract::COMMON_LAB_UNITS as $unit) {
            if (strcasecmp($unit, $expectedUnit) === 0) {
                continue;
            }

            if (! preg_match('/'.preg_quote($unit, '/').'/iu', $text)) {
                continue;
            }

            if (is_numeric($value) && preg_match('/(\d+(?:[.,]\d+)?)\s*'.preg_quote($unit, '/').'/iu', $text, $matches)) {
                $found = (float) str_replace(',', '.', $matches[1]);
                if (abs($found - (float) $value) < 0.01) {
                    throw LaboratoryResultAiExplanationValidationException::invalidStructure('output contradicts input result unit');
                }
            }
        }
    }

    /** @param list<float> $allowed */
    private function isAllowedNumericReference(float $found, array $allowed): bool
    {
        foreach ($allowed as $allowedValue) {
            if (abs($found - $allowedValue) < 0.01) {
                return true;
            }
        }

        return false;
    }

    private function assertSafetyPatterns(string $text): void
    {
        foreach (LaboratoryResultAiExplanationContract::OUTPUT_PROHIBITED_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                throw LaboratoryResultAiExplanationValidationException::invalidStructure('prohibited clinical language detected');
            }
        }
    }

    private function assertNoPiiPatterns(string $text): void
    {
        foreach (LaboratoryResultAiExplanationContract::OUTPUT_PII_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                throw LaboratoryResultAiExplanationValidationException::invalidStructure('prohibited PII pattern detected in output');
            }
        }

        if (preg_match('/\bel paciente [A-ZÁÉÍÓÚÑ][\p{L}]+\s+[A-ZÁÉÍÓÚÑ][\p{L}]+/iu', $text)) {
            throw LaboratoryResultAiExplanationValidationException::invalidStructure('prohibited PII pattern detected in output');
        }

        if (preg_match('/^[A-ZÁÉÍÓÚÑ][\p{L}]+\s+[A-ZÁÉÍÓÚÑ][\p{L}]+\s+tiene\b/iu', trim($text))) {
            throw LaboratoryResultAiExplanationValidationException::invalidStructure('prohibited PII pattern detected in output');
        }
    }
}
