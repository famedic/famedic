<?php

use App\Services\Orders\Drivers\ActiveCampaignOrderDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Order automation drivers
    |--------------------------------------------------------------------------
    |
    | Classes resolved by OrderAutomationDispatcher when an order is completed.
    | Add new drivers here (Email, WhatsApp, Push, Analytics, etc.) without
    | changing OrderAutomationDispatcher code.
    |
    */

    'drivers' => [
        ActiveCampaignOrderDriver::class,
    ],

];
