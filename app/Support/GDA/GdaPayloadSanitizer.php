<?php

namespace App\Support\GDA;

class GdaPayloadSanitizer
{
    /**
     * @var list<string>
     */
    private const HEAVY_BASE64_KEYS = [
        'infogda_resultado_b64',
        'resultado_b64',
        'pdf_base64',
    ];

    public static function sanitize(array $payload): array
    {
        return self::sanitizeRecursive($payload);
    }

    public static function extractResultsPdfBase64(array $payload): ?string
    {
        foreach (self::HEAVY_BASE64_KEYS as $key) {
            if (! empty($payload[$key]) && is_string($payload[$key])) {
                return $payload[$key];
            }
        }

        return null;
    }

    public static function stripDataUriPrefix(string $base64): string
    {
        if (str_contains($base64, 'base64,')) {
            return substr($base64, (int) strrpos($base64, 'base64,') + 7);
        }

        return $base64;
    }

    public static function containsHeavyBase64(array $payload): bool
    {
        return self::extractResultsPdfBase64($payload) !== null
            || self::hasNestedHeavyBase64($payload);
    }

    private static function hasNestedHeavyBase64(array $data): bool
    {
        foreach ($data as $key => $value) {
            if (in_array($key, self::HEAVY_BASE64_KEYS, true)) {
                return true;
            }

            if (is_array($value) && self::hasNestedHeavyBase64($value)) {
                return true;
            }
        }

        return false;
    }

    private static function sanitizeRecursive(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (in_array($key, self::HEAVY_BASE64_KEYS, true)) {
                continue;
            }

            $sanitized[$key] = is_array($value)
                ? self::sanitizeRecursive($value)
                : $value;
        }

        return $sanitized;
    }
}
