<?php

namespace App\Services\Api\V1\Idempotency;

/**
 * Validates Idempotency-Key header values (phase 1).
 */
final class IdempotencyKey
{
    public const HEADER = 'Idempotency-Key';

    public const MIN_LENGTH = 8;

    public const MAX_LENGTH = 128;

    public static function isValid(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        $length = strlen($value);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9._-]+$/', $value);
    }

    /**
     * SHA-256 of the raw key — never persist the plaintext key.
     */
    public static function hash(string $rawKey): string
    {
        return hash('sha256', $rawKey);
    }
}
