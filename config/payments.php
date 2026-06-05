<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Proveedor de pagos por defecto
    |--------------------------------------------------------------------------
    |
    | Valores: efevoopay | hey_banco
    | HEYBANCO_ENABLED debe ser true para tokenizar/cobrar con Hey Banco.
    |
    */

    'default_provider' => env('PAYMENT_PROVIDER', 'efevoopay'),

    'hey_banco_enabled' => env('HEYBANCO_ENABLED', false),

];
