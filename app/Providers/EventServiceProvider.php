<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        Registered::class => [
            // ... otros listeners existentes
        ],
        
        // Agregar este nuevo evento
        \App\Events\UserRegistered::class => [
            \App\Listeners\SyncUserToActiveCampaignListener::class,
        ],
    ];
}