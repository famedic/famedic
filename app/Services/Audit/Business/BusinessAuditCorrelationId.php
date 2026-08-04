<?php

namespace App\Services\Audit\Business;

use Illuminate\Support\Str;

/**
 * Internal correlation ID normalizer for business audit.
 *
 * Does not read or write HTTP headers. Never uses session IDs, payment
 * references, webhook signatures, IP, or User-Agent.
 */
final class BusinessAuditCorrelationId
{
    public const MAX_LENGTH = 128;

    /**
     * Characters allowed in a preserved (caller-supplied) correlation id.
     * UUID (with hyphens) and opaque alphanumeric tokens.
     */
    private const VALID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/';

    /**
     * Normalize a candidate ID or generate a new UUID when absent/invalid.
     *
     * Invalid / oversized / empty values are replaced — never stored as-is.
     * The original invalid value is not logged.
     */
    public static function resolve(?string $candidate = null, ?int $maxLength = null): string
    {
        $max = $maxLength ?? (int) config('business_audit.correlation_id_max_length', self::MAX_LENGTH);
        $max = max(16, min(self::MAX_LENGTH, $max));

        if (is_string($candidate)) {
            $trimmed = trim($candidate);
            if (self::isValid($trimmed, $max)) {
                return $trimmed;
            }
        }

        return (string) Str::uuid();
    }

    public static function isValid(string $value, ?int $maxLength = null): bool
    {
        $max = $maxLength ?? self::MAX_LENGTH;

        if ($value === '' || strlen($value) > $max) {
            return false;
        }

        if (str_contains($value, "\0")) {
            return false;
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            return false;
        }

        return (bool) preg_match(self::VALID_PATTERN, $value);
    }

    public static function generate(): string
    {
        return (string) Str::uuid();
    }
}
