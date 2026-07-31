<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Akubica auth OTP (current production behaviour)
    |--------------------------------------------------------------------------
    |
    | These keys remain the source of truth for IssueAuthOtpAction /
    | VerifyAuthOtpAction until P0-A flags in config/otp.php (`otp.p0a.flags`)
    | are enabled by later blocks. Defaults must stay unchanged in P0-A1.
    |
    | Unified P0-A policy targets live under config('otp.p0a.policy').
    |
    */

    'otp_ttl_minutes' => (int) env('AKUBICA_OTP_TTL_MINUTES', 10),

    'otp_length' => (int) env('AKUBICA_OTP_LENGTH', 6),

    'otp_max_attempts' => (int) env('AKUBICA_OTP_MAX_ATTEMPTS', 5),

    'token_name' => 'akubica',

        /*
    | Effective token TTL advertised in API responses when sanctum_3h is OFF (24h).
    | Sanctum Guard expiration remains config('sanctum.expiration') = 1440.
    | When OTP_P0A_SANCTUM_3H_ENABLED is ON (P0-C1), IssueAkubicaTokenAction persists
    | PAT expires_at using otp.p0a.sanctum.target_expiration_minutes (default 180)
    | without changing sanctum.expiration for non-Akubica tokens.
    */
    'token_ttl_minutes' => (int) env('AKUBICA_TOKEN_TTL_MINUTES', 1440),

    /*
    | Mirror of otp.p0a.sanctum.target_expiration_minutes for documentation / legacy reads.
    | Prefer OTP_P0A_SANCTUM_TOKEN_TTL_MINUTES / TARGET under config/otp.php for P0-C1.
    */
    'token_ttl_minutes_p0a_target' => (int) env('AKUBICA_TOKEN_TTL_MINUTES_P0A_TARGET', 180),

    'token_abilities' => [
        'akubica:auth',
        'akubica:read',
        'akubica:write',
    ],

    'payment_link' => [
        'default_expires_minutes' => (int) env('AKUBICA_PAYMENT_LINK_DEFAULT_EXPIRES_MINUTES', 60),
        'min_expires_minutes' => 5,
        'max_expires_minutes' => 1440,
    ],

];
