<?php

return [
    'api_key' => env('VONAGE_KEY'),
    'api_secret' => env('VONAGE_SECRET'),
    'sms_from' => env('VONAGE_SMS_FROM'),

    /*
    |--------------------------------------------------------------------------
    | SMS API delivery receipts (DLR) — NOT Messages API webhooks
    |--------------------------------------------------------------------------
    |
    | Akúbica OTP uses Vonage SMS REST API ($client->sms()->send()).
    |
    | callback_mode:
    |   per_message — FAMEDIC sets callback URL on each SMS (recommended).
    |                 Do NOT configure a global DLR URL in Vonage Dashboard.
    |   global      — Use Vonage Dashboard → API Settings → Delivery receipts URL only.
    |                 FAMEDIC will NOT set per-message callback.
    |
    | Webhook token: minimum 32 random bytes (use different values per environment).
    |
    | Signature (optional): Vonage Dashboard → API Settings → Signed webhooks.
    |   Enable signed incoming webhooks (contact support@api.vonage.com if needed).
    |   Set Signature method to match VONAGE_SIGNATURE_METHOD (default md5hash).
    |   Use VONAGE_SIGNATURE_SECRET (Signature secret, NOT API secret).
    */
    'sms_dlr' => [
        'enabled' => filter_var(env('VONAGE_SMS_DLR_ENABLED', false), FILTER_VALIDATE_BOOL),
        'webhook_token' => env('VONAGE_SMS_DLR_WEBHOOK_TOKEN'),
        'callback_mode' => env('VONAGE_SMS_DLR_CALLBACK_MODE', 'per_message'),
        'callback_base_url' => env('VONAGE_SMS_DLR_CALLBACK_BASE_URL'),
        'callback_max_length' => (int) env('VONAGE_SMS_DLR_CALLBACK_MAX_LENGTH', 200),
        'signature_secret' => env('VONAGE_SIGNATURE_SECRET'),
        'signature_method' => env('VONAGE_SIGNATURE_METHOD', 'md5hash'),
    ],

    'sms_diagnostic' => [
        'enabled' => filter_var(env('VONAGE_SMS_DIAGNOSTIC_ENABLED', false), FILTER_VALIDATE_BOOL),
        'allowed_destinations' => env('VONAGE_SMS_DIAGNOSTIC_ALLOWED_DESTINATIONS', ''),
        'production_enabled' => filter_var(
            env('VONAGE_SMS_DIAGNOSTIC_PRODUCTION_ENABLED', false),
            FILTER_VALIDATE_BOOL
        ),
        'message' => 'Mensaje de prueba FAMEDIC. La conexión SMS con Vonage funciona correctamente.',
    ],
];
