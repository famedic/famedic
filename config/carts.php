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

    /*
    |--------------------------------------------------------------------------
    | Cita pendiente sin confirmar (monitoring / ActiveCampaign)
    |--------------------------------------------------------------------------
    |
    | Minutos desde laboratory_appointments.created_at con confirmed_at NULL
    | para registrar appointment_pending_5m en cart_events.
    |
    */

    'appointment_pending_after_minutes' => (int) env('CARTS_APPOINTMENT_PENDING_AFTER_MINUTES', 5),

];
