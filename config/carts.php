<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Abandono local (monitoring / admin)
    |--------------------------------------------------------------------------
    |
    | Umbral en minutos sin actividad (carts.updated_at) para considerar un
    | carrito activo como abandonado en FAMEDIC. Independiente del umbral
    | legacy de ActiveCampaign (services.activecampaign.cart_abandoned_minutes).
    |
    */

    'abandoned_after_minutes' => (int) env('CARTS_ABANDONED_AFTER_MINUTES', 30),

];
