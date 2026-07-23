<?php

/**
 * OTP configuration.
 *
 * Legacy keys (`digits`, `expiry`) remain the effective defaults for current
 * lab-results and other callers that already read this file.
 *
 * P0-A policy and feature flags live under `p0a`. Until those flags are enabled
 * by later sprint blocks, application code must keep using legacy / Akubica
 * defaults — this file alone does not change runtime behaviour.
 */

$otpEnvBool = static function (string $key, bool $default): bool {
    $value = env($key);

    if ($value === null) {
        return $default;
    }

    $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    return $filtered === null ? $default : $filtered;
};

$otpEnvInt = static function (string $key, int $default, int $min = 1, ?int $max = null): int {
    $raw = env($key);

    if ($raw === null || $raw === '') {
        return $default;
    }

    if (! is_numeric($raw)) {
        return $default;
    }

    $value = (int) $raw;

    if ($value < $min) {
        return $default;
    }

    if ($max !== null && $value > $max) {
        return $default;
    }

    return $value;
};

$primaryChannel = strtolower(trim((string) env('OTP_P0A_PRIMARY_CHANNEL', 'sms')));
if (! in_array($primaryChannel, ['sms', 'email'], true)) {
    $primaryChannel = 'sms';
}

$fallbackMode = strtolower(trim((string) env('OTP_P0A_FALLBACK_MODE', 'on_sms_failure')));
if (! in_array($fallbackMode, ['never', 'on_sms_failure', 'user_authorized'], true)) {
    $fallbackMode = 'on_sms_failure';
}

return [

    /*
    |--------------------------------------------------------------------------
    | Legacy OTP defaults (current production behaviour)
    |--------------------------------------------------------------------------
    |
    | LaboratoryResultsOtpController and related flows use these keys today.
    | Do not change defaults here in P0-A1.
    |
    */

    'digits' => 6,

    'expiry' => 10,

    /*
    |--------------------------------------------------------------------------
    | P0-A — Feature flags (all behaviour flags OFF by default)
    |--------------------------------------------------------------------------
    |
    | Master flag `infrastructure_enabled` must stay false until P0-A2+.
    | Downstream blocks should gate on these flags before applying policy.
    |
    */

    'p0a' => [

        'flags' => [
            /** Nueva infraestructura / servicio OTP reutilizable (P0-A2+). */
            'infrastructure_enabled' => $otpEnvBool('OTP_P0A_INFRASTRUCTURE_ENABLED', false),

            /** Entrega SMS para flujos Akubica (P0-A3+). */
            'sms_delivery_enabled' => $otpEnvBool('OTP_P0A_SMS_DELIVERY_ENABLED', false),

            /** Fallback controlado a correo (P0-A3+). */
            'email_fallback_enabled' => $otpEnvBool('OTP_P0A_EMAIL_FALLBACK_ENABLED', false),

            /** Cooldown, tope de reenvíos, intentos y bloqueo (P0-A3+). OFF = no wiring productivo. */
            'anti_abuse_enabled' => $otpEnvBool('OTP_P0A_ANTI_ABUSE_ENABLED', false),

            /** Aplicar vigencia Sanctum de 3 horas (P0-A6+). No altera sanctum.expiration. */
            'sanctum_3h_enabled' => $otpEnvBool('OTP_P0A_SANCTUM_3H_ENABLED', false),

            /** Step-up OTP antes de ligas/acceso a resultados (P0-A7+). */
            'step_up_results_enabled' => $otpEnvBool('OTP_P0A_STEP_UP_RESULTS_ENABLED', false),

            /** Step-up OTP antes de ligas/acceso a facturas (P0-A7+). */
            'step_up_invoices_enabled' => $otpEnvBool('OTP_P0A_STEP_UP_INVOICES_ENABLED', false),

            /** Exigir step-up también en descargas Bearer directas (P0-A7+). */
            'step_up_bearer_downloads_enabled' => $otpEnvBool(
                'OTP_P0A_STEP_UP_BEARER_DOWNLOADS_ENABLED',
                false
            ),
        ],

        /*
        |--------------------------------------------------------------------------
        | Target policy (DEC-001 … DEC-004) — inactive until flags enable it
        |--------------------------------------------------------------------------
        */

        'policy' => [
            'ttl_minutes' => $otpEnvInt('OTP_P0A_TTL_MINUTES', 5, 1, 60),
            'length' => $otpEnvInt('OTP_P0A_LENGTH', 6, 4, 10),
            'max_attempts' => $otpEnvInt('OTP_P0A_MAX_ATTEMPTS', 5, 1, 20),
            'cooldown_seconds' => $otpEnvInt('OTP_P0A_COOLDOWN_SECONDS', 60, 1, 3600),
            'resend_window_minutes' => $otpEnvInt('OTP_P0A_RESEND_WINDOW_MINUTES', 30, 1, 1440),
            /** Additional resends after the initial request (initial does not count). */
            'max_resends' => $otpEnvInt('OTP_P0A_MAX_RESENDS', 3, 0, 20),
            'block_minutes' => $otpEnvInt('OTP_P0A_BLOCK_MINUTES', 30, 1, 1440),
            'require_verified_phone' => $otpEnvBool('OTP_P0A_REQUIRE_VERIFIED_PHONE', true),
            'primary_channel' => $primaryChannel,
            'fallback_mode' => $fallbackMode,
            'audit_enabled' => $otpEnvBool('OTP_P0A_AUDIT_ENABLED', true),
        ],

        /*
        |--------------------------------------------------------------------------
        | Step-up grant (DEC-006) — config only; no persistence/validation yet
        |--------------------------------------------------------------------------
        */

        'step_up' => [
            'grant_ttl_minutes' => $otpEnvInt('OTP_P0A_STEP_UP_GRANT_TTL_MINUTES', 10, 1, 120),
            'bind_to_sanctum_token' => $otpEnvBool('OTP_P0A_STEP_UP_BIND_SANCTUM_TOKEN', true),
            'bind_to_purpose' => $otpEnvBool('OTP_P0A_STEP_UP_BIND_PURPOSE', true),
            'bind_to_resource' => $otpEnvBool('OTP_P0A_STEP_UP_BIND_RESOURCE', true),
        ],

        /*
        |--------------------------------------------------------------------------
        | Sanctum target (DEC-005) — does NOT change config/sanctum.php
        |--------------------------------------------------------------------------
        */

        'sanctum' => [
            'target_expiration_minutes' => $otpEnvInt(
                'OTP_P0A_SANCTUM_TARGET_EXPIRATION_MINUTES',
                180,
                1,
                10080
            ),
            'current_expiration_minutes' => (int) env('SANCTUM_TOKEN_EXPIRATION', 1440),
        ],

        /*
        |--------------------------------------------------------------------------
        | Secure links contract (DEC-007) — prepare only; implement later
        |--------------------------------------------------------------------------
        */

        'secure_links' => [
            'ttl_minutes' => $otpEnvInt('OTP_P0A_SECURE_LINK_TTL_MINUTES', 60, 1, 1440),
            'max_opens' => $otpEnvInt('OTP_P0A_SECURE_LINK_MAX_OPENS', 5, 1, 100),
        ],

        /*
        |--------------------------------------------------------------------------
        | Anti-abuse (P0-A3) — infrastructure only; gate productive callers on
        | flags.anti_abuse_enabled. Cooldown / max_resends / block_minutes /
        | max_attempts remain under policy.* above.
        |--------------------------------------------------------------------------
        |
        | identity_max_requests: hard ceiling per identity+purpose window
        |   (effective max = min(this, 1 + policy.max_resends)).
        | ip_max_requests: ceiling per hashed IP+purpose window.
        | rate_limit_window_minutes: sliding/reset window for counters.
        | retention_days: documented purge horizon for otp_abuse_events
        |   (command otp:purge-abuse-events exists; NOT scheduled in prod yet).
        |
        */

        'anti_abuse' => [
            'identity_max_requests' => $otpEnvInt('OTP_P0A_IDENTITY_MAX_REQUESTS', 4, 1, 100),
            'ip_max_requests' => $otpEnvInt('OTP_P0A_IP_MAX_REQUESTS', 20, 1, 500),
            'rate_limit_window_minutes' => $otpEnvInt(
                'OTP_P0A_RATE_LIMIT_WINDOW_MINUTES',
                30,
                1,
                1440
            ),
            'retention_days' => $otpEnvInt('OTP_P0A_ABUSE_RETENTION_DAYS', 30, 1, 365),
        ],
    ],

];
