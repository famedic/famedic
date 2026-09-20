<?php

namespace App\Services\LaboratoryResults\AiExplanation\Contract;

final class LaboratoryResultAiExplanationInputHash
{
    /** @param array<string, mixed> $input */
    public static function compute(array $input, int|string $promptVersion): string
    {
        $canonical = [
            'analyte_code' => data_get($input, 'analyte.code'),
            'value' => self::normalizeScalar(data_get($input, 'result.value')),
            'value_type' => data_get($input, 'result.value_type'),
            'unit' => data_get($input, 'result.unit'),
            'reference_text' => data_get($input, 'reference.text'),
            'reference_low' => self::normalizeScalar(data_get($input, 'reference.low')),
            'reference_high' => self::normalizeScalar(data_get($input, 'reference.high')),
            'reference_status' => data_get($input, 'status'),
            'abnormal_flag' => (bool) data_get($input, 'abnormal'),
            'prompt_version' => (string) $promptVersion,
        ];

        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return hash('sha256', $json !== false ? $json : '');
    }

    private static function normalizeScalar(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }

        return (string) $value;
    }
}
