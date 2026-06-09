<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Proveedor de tarjetas por defecto
    |--------------------------------------------------------------------------
    |
    | Valores: efevoopay | hey_banco
    | En beta/producción Banregio usar: PAYMENT_PROVIDER=hey_banco
    |
    */

    'default_provider' => env('PAYMENT_PROVIDER', 'efevoopay'),

    /*
    |--------------------------------------------------------------------------
    | EfevooPay habilitado para nuevos pagos / tokenización visible
    |--------------------------------------------------------------------------
    |
    | false = ocultar tarjetas EfevooPay en checkout y rechazar IDs legacy
    |         numéricos en nuevos cobros. No borra datos históricos.
    |
    */

    'efevoopay_enabled' => env('EFEVOOPAY_ENABLED', true),

    'legacy_efevoo_rejection_message' => 'Este método de pago ya no está disponible. Agrega o selecciona una tarjeta Banregio.',

];
