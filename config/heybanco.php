<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hey Banco / Banregio Colecto
    |--------------------------------------------------------------------------
    |
    | Integración ADQ (tokenización y cobros) y configuración 3DS (preparada).
    | En sandbox usar mode=AUT para aprobar siempre; en producción usar PRD.
    |
    */

    'enabled' => env('HEYBANCO_ENABLED', false),

    'env' => env('HEYBANCO_ENV', 'sandbox'),

    'adq_url' => env('HEYBANCO_ADQ_URL', 'https://testcolecto.banregio.com/adq/'),

    '3ds_url' => env('HEYBANCO_3DS_URL', 'https://testcolecto.banregio.com/tds/vistas/recepcion3ds.zul'),

    'token_affiliation' => env('HEYBANCO_TOKEN_AFFILIATION', '8379502'),

    'token_media_id' => env('HEYBANCO_TOKEN_MEDIA_ID', 'JCZSH8TV'),

    '3ds_affiliation' => env('HEYBANCO_3DS_AFFILIATION', '8379507'),

    '3ds_media_id' => env('HEYBANCO_3DS_MEDIA_ID', 'AW1XCK1J'),

    '3ds_media_id_alt' => env('HEYBANCO_3DS_MEDIA_ID_ALT', '3MX2TUVQ'),

    '3ds_secret_key' => env('HEYBANCO_3DS_SECRET_KEY', ''),

    'mode' => env('HEYBANCO_MODE', 'AUT'),

    'timeout' => (int) env('HEYBANCO_TIMEOUT', 30),

    'currency' => 'MXN',

    'provider_key' => 'hey_banco',

    'test_cards' => [
        [
            'brand' => 'visa',
            'number' => '4456530000001096',
            'exp_month' => '12',
            'exp_year' => '26',
            'cvv' => '123',
        ],
        [
            'brand' => 'mastercard',
            'number' => '5200000000001096',
            'exp_month' => '12',
            'exp_year' => '26',
            'cvv' => '123',
        ],
        [
            'brand' => 'amex',
            'number' => '340000000001098',
            'exp_month' => '12',
            'exp_year' => '26',
            'cvv' => '123',
        ],
    ],

];
