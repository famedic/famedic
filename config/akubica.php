<?php

return [

    'otp_ttl_minutes' => (int) env('AKUBICA_OTP_TTL_MINUTES', 10),

    'otp_length' => (int) env('AKUBICA_OTP_LENGTH', 6),

    'otp_max_attempts' => (int) env('AKUBICA_OTP_MAX_ATTEMPTS', 5),

    'token_name' => 'akubica',

    'token_ttl_minutes' => (int) env('AKUBICA_TOKEN_TTL_MINUTES', 1440),

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
