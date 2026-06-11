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

];
