<?php

return [
    // Ambiente productivo
    'environment' => env('EFEVOO_ENVIRONMENT', 'production'),
    
    // Configuración PRODUCTIVA (basada en script exitoso)
    'api_url' => env('EFEVOO_API_URL', 'https://intgapi.efevoopay.com/v1/apiservice'),
    'api_user' => env('EFEVOO_API_USER', 'Famedic'),
    'api_key' => env('EFEVOO_API_KEY', '9e21f21d434ba4ab219a3cd3ad6c3171c142ece4ff87b0f12b4035106b22e162'),
    'totp_secret' => env('EFEVOO_TOTP_SECRET', 'PIBOFBXR6P3TWXRFJQF5VRAMV5RFR3Y5'),
    'clave' => env('EFEVOO_CLAVE', '2NF2g75uJ4VXqJ7D'),
    'cliente' => env('EFEVOO_CLIENTE', 'GFAMEDIC'),
    'vector' => env('EFEVOO_VECTOR', '1XGYCKGIneuhhGFq'),
    'idagep_empresa' => env('EFEVOO_IDAGEP_EMPRESA', 1827),
    
    // Token fijo proporcionado por EFEVOOPAY
    'fixed_token' => env('EFEVOOPAY_FIXED_TOKEN', 'QUZqMHdBVU50ZFpxMktYMEMxUjFiYkhSeVRiTk5NYXpoTTE4RWpodGRKND0='),
    
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
    'fiid_comercio' => env('EFEVOO_FIID_COMERCIO', '9890713'),
    'requires_3ds' => env('EFEVOO_REQUIRES_3DS', false), // Desactivar temporalmente
    'iframe_timeout' => 300,
    
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