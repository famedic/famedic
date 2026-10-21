<?php

$envInt = static function (string $key, int $default, int $min, int $max): int {
    $raw = env($key);
    if ($raw === null || $raw === '' || ! is_numeric($raw)) {
        return $default;
    }

    return max($min, min($max, (int) $raw));
};

$sandboxKeyId = env('ATIX_SANDBOX_INTEGRATION_KEY_ID');
$sandboxSecret = env('ATIX_SANDBOX_HMAC_SECRET');

return [
    'sandbox' => [
        'keys' => is_string($sandboxKeyId) && $sandboxKeyId !== ''
            && is_string($sandboxSecret) && $sandboxSecret !== ''
            ? [$sandboxKeyId => $sandboxSecret]
            : [],
        'algorithm' => strtolower((string) env('ATIX_SANDBOX_HMAC_ALGORITHM', 'sha256')),
        // Phone normalization is currently Mexico-only; no other country rule is assumed.
        'phone_country' => strtoupper((string) env('ATIX_SANDBOX_PHONE_COUNTRY', 'MX')),
        'timestamp_tolerance_seconds' => $envInt('ATIX_SANDBOX_TIMESTAMP_TOLERANCE_SECONDS', 300, 1, 900),
        'nonce_ttl_seconds' => $envInt('ATIX_SANDBOX_NONCE_TTL_SECONDS', 300, 1, 86400),
        'binding_ttl_hours' => $envInt('ATIX_SANDBOX_BINDING_TTL_HOURS', 24, 1, 24),
        'allowed_channels' => array_values(array_filter(array_map('trim', explode(',', (string) env('ATIX_SANDBOX_ALLOWED_CHANNELS', 'whatsapp'))))),
        'allowed_audiences' => array_values(array_filter(array_map('trim', explode(',', (string) env('ATIX_SANDBOX_ALLOWED_AUDIENCES', 'leo'))))),
        'rate_limit_per_minute' => $envInt('ATIX_SANDBOX_RATE_LIMIT_PER_MINUTE', 60, 1, 10000),
        'rate_limit_retry_after_seconds' => $envInt('ATIX_SANDBOX_RATE_LIMIT_RETRY_AFTER_SECONDS', 60, 1, 3600),
        'connect_timeout_seconds' => $envInt('ATIX_SANDBOX_CONNECT_TIMEOUT_SECONDS', 2, 1, 30),
        'read_timeout_seconds' => $envInt('ATIX_SANDBOX_READ_TIMEOUT_SECONDS', 5, 1, 60),
        'total_timeout_seconds' => $envInt('ATIX_SANDBOX_TOTAL_TIMEOUT_SECONDS', 8, 1, 120),
        'max_retries' => $envInt('ATIX_SANDBOX_MAX_RETRIES', 3, 0, 10),
        'retry_statuses' => [408, 429, 502, 503, 504],
    ],
];
