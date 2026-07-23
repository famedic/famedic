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
    | Effective token TTL advertised in API responses today (24h).
    | Sanctum Guard expiration remains config('sanctum.expiration') = 1440.
    | P0-A target (180) is config('otp.p0a.sanctum.target_expiration_minutes')
    | and only applies when otp.p0a.flags.sanctum_3h_enabled is true (P0-A6).
    */
    'token_ttl_minutes' => (int) env('AKUBICA_TOKEN_TTL_MINUTES', 1440),

    /*
    | Documented target for when OTP_P0A_SANCTUM_3H_ENABLED is activated.
    | Does not alter IssueAkubicaTokenAction or sanctum.expiration in P0-A1.
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
