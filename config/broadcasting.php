<?php

return [
    'default' => env('BROADCAST_DRIVER', 'pusher'),
    
    'connections' => [
        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY', 'efevoopay-local'),
            'secret' => env('PUSHER_APP_SECRET', 'efevoopay-local-secret'),
            'app_id' => env('PUSHER_APP_ID', 'efevoopay-local-app'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'),
                'host' => env('PUSHER_HOST', '127.0.0.1') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => env('PUSHER_PORT', 6001),
                'scheme' => env('PUSHER_SCHEME', 'http'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME') === 'https',
                'curl_options' => [
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_SSL_VERIFYPEER => 0,
                ],
            ],
            'client_options' => [
                // Guzzle client options
            ],
        ],
        
        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],
        
        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
        ],
        
        'log' => [
            'driver' => 'log',
        ],
        
        'null' => [
            'driver' => 'null',
        ],
    ],
];