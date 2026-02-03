<?php

return [
    // Solo un ambiente para simplificar
    'environment' => env('EFEVOO_ENVIRONMENT', 'test'),
    
    // Configuración única (misma para test y producción)
    'api_url' => env('EFEVOO_API_URL', 'https://test-intgapi.efevoopay.com/v1/apiservice'),
    'api_user' => env('EFEVOO_API_USER', 'Efevoo Pay'),
    'api_key' => env('EFEVOO_API_KEY', 'Hq#J0hs)jK+YqF6J'),
    'totp_secret' => env('EFEVOO_TOTP_SECRET', 'I7WHOTIN7VVQFAMSDI4X2WFTTAEP653Q'),
    'clave' => env('EFEVOO_CLAVE', '6nugHedWzw27MNB8'),
    'cliente' => env('EFEVOO_CLIENTE', 'TestFAMEDIC'),
    'vector' => env('EFEVOO_VECTOR', 'MszjlcnTjGLNpNy3'),
    
    // Configuración para forzar URL específica en producción
    'force_production_url' => env('EFEVOO_FORCE_PRODUCTION_URL', 'https://test-intgapi.efevoopay.com/v1/apiservice'),
    
    // Configuración global
    'timeout' => 30,
    'verify_ssl' => env('EFEVOO_VERIFY_SSL', false),
    'log_requests' => env('EFEVOO_LOG_REQUESTS', true),
    'log_channel' => env('EFEVOO_LOG_CHANNEL', 'stack'),
    
    // Montos de prueba (en centavos)
    'test_amounts' => [
        'min' => 1,      // $0.01 MXN
        'default' => 150, // $1.50 MXN
        'max' => 300,    // $3.00 MXN
    ],
    
    // Token fijo si es necesario
    'fixed_token' => env('EFEVOOPAY_FIXED_TOKEN'),
    
    // Códigos de respuesta
    'response_codes' => [
        '00' => 'Aprobado o completado con éxito',
        '05' => 'No honrar',
        '30' => 'Error de formato',
        '100' => 'Operación exitosa',
        '102' => 'Credenciales incorrectas',
        '103' => 'Token inválido o expirado',
    ],
    
    // Formatos de fecha
    'date_formats' => [
        'expiration' => 'my',  // MMYY para tarjetas
        'api' => 'Y-m-d H:i:s',
    ],

    // Configuración del simulador
    'force_simulation' => env('EFEVOOPAY_FORCE_SIMULATION', false),
    'simulator' => [
        'weekend_mode' => env('EFEVOOPAY_SIMULATOR_WEEKEND', true),
        'response_delay_min' => 500, // milisegundos
        'response_delay_max' => 2000, // milisegundos
    ],
    
    // Configuración específica para producción (si se usa EfevooPayProductionService)
    'production' => [
        'api_url' => env('EFEVOO_PRODUCTION_API_URL', 'https://test-intgapi.efevoopay.com/v1/apiservice'),
        'api_key' => env('EFEVOO_PRODUCTION_API_KEY', env('EFEVOO_API_KEY')),
    ],
];