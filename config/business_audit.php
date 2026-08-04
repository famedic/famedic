<?php

/**
 * Business audit trail — channel-agnostic append-only infrastructure (Block 6A).
 *
 * Independent from API V1 audit (`config/api_v1.php` → audit). Enabling one
 * flag never enables the other. Default OFF. No business instrumentation
 * in this phase. Cleanup / retention flags belong to a later block.
 */

$envBool = static function (string $key, bool $default): bool {
    $raw = env($key);
    if ($raw === null) {
        return $default;
    }

    return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
};

$envInt = static function (string $key, int $default, int $min, int $max): int {
    $raw = env($key);
    if ($raw === null || $raw === '' || ! is_numeric($raw)) {
        return $default;
    }

    $value = (int) $raw;

    return max($min, min($max, $value));
};

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    */

    'enabled' => $envBool('BUSINESS_AUDIT_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Metadata limits
    |--------------------------------------------------------------------------
    |
    | Allowlist-first metadata. Exceeding max bytes discards the entire
    | metadata object (fail-soft) — never truncate silently.
    |
    */

    'max_metadata_bytes' => $envInt(
        'BUSINESS_AUDIT_MAX_METADATA_BYTES',
        2048,
        1,
        65536
    ),

    'max_metadata_depth' => $envInt(
        'BUSINESS_AUDIT_MAX_METADATA_DEPTH',
        2,
        1,
        5
    ),

    'max_metadata_keys' => $envInt(
        'BUSINESS_AUDIT_MAX_METADATA_KEYS',
        32,
        1,
        128
    ),

    /*
    |--------------------------------------------------------------------------
    | Correlation ID
    |--------------------------------------------------------------------------
    */

    'correlation_id_max_length' => $envInt(
        'BUSINESS_AUDIT_CORRELATION_ID_MAX_LENGTH',
        128,
        16,
        128
    ),

];
