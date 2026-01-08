<?php

return [
    'api_key' => env('EFEVOOPAY_API_KEY'),
    'api_secret' => env('EFEVOOPAY_API_SECRET'),
    'totp_secret' => env('EFEVOOPAY_TOTP_SECRET'),
    
    'urls' => [
        'api' => env('EFEVOOPAY_API_URL', 'https://ecommapi.efevoopay.com/ecommerce'),
        'checkout' => env('EFEVOOPAY_CHECKOUT_URL', 'https://efevoopay.com/CheckOut'),
        'wss' => env('EFEVOOPAY_WSS_URL', 'wss://ecommwss.efevoopay.com'),
    ],
    
    'defaults' => [
        'group' => 'wmx_api',
        'website' => env('APP_URL'),
    ],
    
    'currency' => 'MXN',
    'timeout' => 30,
    
    // Configuración de reintentos
    'retry' => [
        'attempts' => 3,
        'delay' => 2000, // milisegundos
    ],
];