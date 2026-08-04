<?php

/**
 * Akubica API v1 cross-cutting configuration (idempotency, etc.).
 *
 * Defaults are safe / OFF. Tests force API_V1_IDEMPOTENCY_ENABLED=false in phpunit.xml.
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
    | HTTP Idempotency (phase 1) — Idempotency-Key
    |--------------------------------------------------------------------------
    |
    | Optional client key on selected write routes. Does not guarantee
    | exactly-once when an external side effect completes but the response
    | was never persisted (failed_uncertain). Redis is NOT required.
    |
    */

    'idempotency' => [
        'enabled' => $envBool('API_V1_IDEMPOTENCY_ENABLED', false),

        'ttl_hours' => $envInt('API_V1_IDEMPOTENCY_TTL_HOURS', 24, 1, 72),

        'processing_lease_seconds' => $envInt(
            'API_V1_IDEMPOTENCY_PROCESSING_LEASE_SECONDS',
            60,
            5,
            600
        ),

        'max_response_bytes' => $envInt(
            'API_V1_IDEMPOTENCY_MAX_RESPONSE_BYTES',
            65536,
            1,
            524288
        ),

        'prune' => [
            'enabled' => $envBool('API_V1_IDEMPOTENCY_PRUNE_ENABLED', false),
            'default_batch' => $envInt('API_V1_IDEMPOTENCY_PRUNE_BATCH', 1000, 1, 10000),
            'schedule_time' => env('API_V1_IDEMPOTENCY_PRUNE_SCHEDULE_TIME', '04:00'),
        ],
    ],

];
