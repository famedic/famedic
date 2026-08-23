<?php

return [
    // Ambiente productivo
    'environment' => env('EFEVOO_ENVIRONMENT', 'production'),

    // Configuración PRODUCTIVA (basada en script exitoso)
    'api_url' => env('EFEVOO_API_URL', 'https://intgapi.efevoopay.com/v1/apiservice'),
    'api_user' => env('EFEVOO_API_USER'),
    'api_key' => env('EFEVOO_API_KEY'),
    'totp_secret' => env('EFEVOO_TOTP_SECRET'),
    'clave' => env('EFEVOO_CLAVE'),
    'cliente' => env('EFEVOO_CLIENTE'),
    'vector' => env('EFEVOO_VECTOR'),
    'idagep_empresa' => env('EFEVOO_IDAGEP_EMPRESA'),

    // Token fijo proporcionado por EFEVOOPAY
    'fixed_token' => env('EFEVOOPAY_FIXED_TOKEN'),

    // Configuración global
    'timeout' => 30,
    'verify_ssl' => env('EFEVOO_VERIFY_SSL', false),
    'log_requests' => env('EFEVOO_LOG_REQUESTS', true),
    'log_channel' => env('EFEVOO_LOG_CHANNEL', 'stack'),

    // Montos de prueba (en centavos)
    'test_amounts' => [
        'min' => 1,      // $0.01 MXN
        'default' => 150, // $1.50 MXN (monto que funcionó)
        'max' => 300,    // $3.00 MXN
    ],

    /*
    | Montos de verificación 3DS (Fase 5C) — separados explícitamente.
    | Valores iniciales = test_amounts.default (150 centavos = $1.50 MXN).
    | No cambiar comportamiento hasta revisión operativa con EfevooPay.
    */
    'three_ds_verification_amount_cents' => (int) env('EFEVOO_THREE_DS_VERIFICATION_AMOUNT_CENTS', 150),
    'tokenization_verification_amount_cents' => (int) env('EFEVOO_TOKENIZATION_VERIFICATION_AMOUNT_CENTS', 150),
    'verification_currency' => env('EFEVOO_VERIFICATION_CURRENCY', 'MXN'),

    /*
    | Polling 3DS (frontend ThreeDSRedirect: delay 5s, interval 5s).
    | max_external_status_polls alineado con sensitive_card_data TTL (5 min ≈ 60 polls).
    */
    'polling' => [
        'frontend_delay_ms' => 5000,
        'frontend_interval_ms' => 5000,
        'max_external_status_polls' => (int) env('EFEVOO_3DS_MAX_EXTERNAL_STATUS_POLLS', 60),
        'get_status_lock_seconds' => (int) env('EFEVOO_3DS_GET_STATUS_LOCK_SECONDS', 45),
        'tokenize_lock_seconds' => (int) env('EFEVOO_3DS_TOKENIZE_LOCK_SECONDS', 90),
    ],

    // Códigos de respuesta (basados en respuestas reales)
    'response_codes' => [
        '00' => 'Aprobado o completado con éxito',
        '05' => 'No honrar',
        '30' => 'Error de formato',
        '100' => 'Token generado exitosamente',
        '51' => 'Fondos insuficientes',
        '54' => 'Tarjeta vencida',
        '55' => 'Contraseña incorrecta',
        '57' => 'Transacción no permitida',
        '61' => 'Monto excede límite',
        '62' => 'Tarjeta restringida',
        '96' => 'Sistema no disponible',
    ],

    // Comisión por transacción: 2.9% del monto cobrado + 16% IVA sobre la comisión.
    // EfevooPay cotiza "2.9% + IVA"; el porcentaje efectivo sobre el monto es 2.99%.
    'commission' => [
        'rate_percent' => (float) env('EFEVOO_COMMISSION_RATE_PERCENT', 2.99),
        'vat_rate_percent' => (float) env('EFEVOO_COMMISSION_VAT_RATE_PERCENT', 16),
    ],

    // Configuración del simulador (desactivar en producción)
    'force_simulation' => env('EFEVOOPAY_FORCE_SIMULATION', false),

    // Configuración de operaciones
    'operations' => [
        'tokenize' => [
            'method' => 'getTokenize',
            'token_type' => 'fixed', // Usar token fijo para tokenización
        ],
        'payment' => [
            'method' => 'getPayment',
            'token_type' => 'dynamic', // SIEMPRE dinámico para pagos
        ],
        'search' => [
            'method' => 'getTranSearch',
            'token_type' => 'dynamic',
        ],
        'refund' => [
            'method' => 'getRefund',
            'token_type' => 'dynamic',
        ],
        'client_token' => [
            'method' => 'getClientToken',
            'token_type' => 'dynamic',
        ],
    ],

    // Para 3DS (si es necesario)
    'fiid_comercio' => env('EFEVOO_FIID_COMERCIO'),
    'requires_3ds' => env('EFEVOO_REQUIRES_3DS', false), // Desactivar temporalmente
    'iframe_timeout' => 300,
    // TTL inicial alineado con iframe_timeout actual (300s = 5 minutos).
    'authentication_attempt_ttl_minutes' => (int) env('EFEVOO_AUTH_ATTEMPT_TTL_MINUTES', 5),

    /*
    | Contención Fase 5B: datos sensibles temporales durante 3DS.
    | No sustituye hosted fields ni eliminación de CVV en GetStatus del proveedor.
    */
    'sensitive_card_data' => [
        'containment_enabled' => (bool) env('EFEVOO_SENSITIVE_CARD_DATA_CONTAINMENT', true),
        'ttl_minutes' => (int) env('EFEVOO_SENSITIVE_CARD_DATA_TTL_MINUTES', 5),
        'messages' => [
            'missing_or_expired' => 'La verificación ya no puede continuar de forma segura. Inicia una nueva verificación.',
            'generic_failure' => 'No se pudo completar la verificación. Intenta nuevamente o contacta a soporte.',
            'confirmation_pending' => 'Estamos confirmando el estado de tu verificación.',
            'containment_disabled' => 'La verificación con tarjeta no está disponible temporalmente. Intenta más tarde o contacta a soporte.',
        ],
        'purge_command' => [
            'default_dry_run' => true,
            'batch_size' => (int) env('EFEVOO_SENSITIVE_CARD_DATA_PURGE_BATCH', 100),
            // TTL 5 min → GC cada 5 min (dry-run) mientras se valida; --apply tras aprobación operativa.
            // Ventana máxima residual ≈ TTL (5m) + intervalo scheduler (5m) + jitter (~1m) = ~11 min
            // antes de purga física en abandonos; sesión Laravel global sigue siendo SESSION_LIFETIME.
            'recommended_frequency' => 'every_five_minutes',
            'recommended_apply_frequency' => 'every_five_minutes',
            'recommended_dry_run_frequency' => 'every_five_minutes',
            'recommended_window' => '24x7 with withoutOverlapping(4) and onOneServer',
            'max_residual_window_minutes' => 11,
        ],
    ],
    // Ventana de recuperacion del checkout/origen. Un contexto puede cubrir varios intentos 3DS.
    'recovery_context_ttl_minutes' => (int) env('EFEVOO_RECOVERY_CONTEXT_TTL_MINUTES', 30),
    'recovery' => [
        'max_attempts_per_context' => (int) env('EFEVOO_RECOVERY_MAX_ATTEMPTS_PER_CONTEXT', 3),
        'attempt_window_minutes' => (int) env('EFEVOO_RECOVERY_ATTEMPT_WINDOW_MINUTES', 30),
        'technical_error_cooldown_seconds' => (int) env('EFEVOO_RECOVERY_TECHNICAL_ERROR_COOLDOWN_SECONDS', 60),
        'prioritize_different_card_after_failures' => (int) env('EFEVOO_RECOVERY_PRIORITIZE_DIFFERENT_CARD_AFTER', 2),
        'navigation_session_ttl_minutes' => (int) env('EFEVOO_RECOVERY_NAVIGATION_SESSION_TTL_MINUTES', 10),
        'status_refresh_dedupe_minutes' => (int) env('EFEVOO_RECOVERY_STATUS_REFRESH_DEDUPE_MINUTES', 5),
    ],

    'rate_limits' => [
        'health' => [
            'max_attempts' => (int) env('EFEVOO_RATE_LIMIT_HEALTH_MAX_ATTEMPTS', 10),
            'decay_minutes' => (int) env('EFEVOO_RATE_LIMIT_HEALTH_DECAY_MINUTES', 1),
        ],
        'tokenize' => [
            'max_attempts' => (int) env('EFEVOO_RATE_LIMIT_TOKENIZE_MAX_ATTEMPTS', 5),
            'decay_minutes' => (int) env('EFEVOO_RATE_LIMIT_TOKENIZE_DECAY_MINUTES', 1),
        ],
        'tokens' => [
            'max_attempts' => (int) env('EFEVOO_RATE_LIMIT_TOKENS_MAX_ATTEMPTS', 30),
            'decay_minutes' => (int) env('EFEVOO_RATE_LIMIT_TOKENS_DECAY_MINUTES', 1),
        ],
        'payment' => [
            'max_attempts' => (int) env('EFEVOO_RATE_LIMIT_PAYMENT_MAX_ATTEMPTS', 6),
            'decay_minutes' => (int) env('EFEVOO_RATE_LIMIT_PAYMENT_DECAY_MINUTES', 1),
        ],
        'refund' => [
            'max_attempts' => (int) env('EFEVOO_RATE_LIMIT_REFUND_MAX_ATTEMPTS', 3),
            'decay_minutes' => (int) env('EFEVOO_RATE_LIMIT_REFUND_DECAY_MINUTES', 1),
        ],
        'search' => [
            'max_attempts' => (int) env('EFEVOO_RATE_LIMIT_SEARCH_MAX_ATTEMPTS', 20),
            'decay_minutes' => (int) env('EFEVOO_RATE_LIMIT_SEARCH_DECAY_MINUTES', 1),
        ],
        '3ds_status' => [
            'max_attempts' => (int) env('EFEVOO_RATE_LIMIT_3DS_STATUS_MAX_ATTEMPTS', 30),
            'decay_minutes' => (int) env('EFEVOO_RATE_LIMIT_3DS_STATUS_DECAY_MINUTES', 1),
        ],
        'recovery' => [
            'max_attempts' => (int) env('EFEVOO_RATE_LIMIT_RECOVERY_MAX_ATTEMPTS', 10),
            'decay_minutes' => (int) env('EFEVOO_RATE_LIMIT_RECOVERY_DECAY_MINUTES', 1),
        ],
        'recovery_status' => [
            'max_attempts' => (int) env('EFEVOO_RATE_LIMIT_RECOVERY_STATUS_MAX_ATTEMPTS', 20),
            'decay_minutes' => (int) env('EFEVOO_RATE_LIMIT_RECOVERY_STATUS_DECAY_MINUTES', 1),
        ],
        'recovery_sync' => [
            'max_attempts' => (int) env('EFEVOO_RATE_LIMIT_RECOVERY_SYNC_MAX_ATTEMPTS', 10),
            'decay_minutes' => (int) env('EFEVOO_RATE_LIMIT_RECOVERY_SYNC_DECAY_MINUTES', 1),
        ],
    ],

    // Headers adicionales para evitar problemas CORS
    'additional_headers' => [
        'Origin: https://efevoopay.com',
        'Referer: https://efevoopay.com/',
        'Accept: application/json',
        'Accept-Language: es-MX,es;q=0.9',
        'Accept-Encoding: gzip, deflate',
        'Connection: keep-alive',
        'Cache-Control: no-cache',
    ],
];
