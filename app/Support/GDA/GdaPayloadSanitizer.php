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

    /**
     * Sanitiza payloads para depuración: omite PDF base64 pesado pero deja una marca
     * con el tamaño, y enmascara tokens sensibles.
     */
    public static function sanitizeForDebug(array $payload): array
    {
        return self::sanitizeForDebugRecursive($payload);
    }

    private static function sanitizeForDebugRecursive(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (in_array($key, self::HEAVY_BASE64_KEYS, true) && is_string($value) && $value !== '') {
                $bytes = strlen($value);
                $sanitized[$key] = sprintf(
                    '[omitted base64: %s chars ≈ %s KB]',
                    number_format($bytes),
                    number_format($bytes / 1024, 1)
                );

                continue;
            }

            if ($key === 'token' && is_string($value) && $value !== '') {
                $sanitized[$key] = self::maskSecret($value);

                continue;
            }

            $sanitized[$key] = is_array($value)
                ? self::sanitizeForDebugRecursive($value)
                : $value;
        }

        return $sanitized;
    }

    private static function maskSecret(string $value): string
    {
        $length = strlen($value);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 4).str_repeat('*', max(4, $length - 8)).substr($value, -4);
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
