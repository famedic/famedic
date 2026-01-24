<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Commands
{
    /**
     * Registrar todos los comandos de la aplicación
     */
    public static function register(): array
    {
        return [
            \App\Console\Commands\SyncUserToActiveCampaignCommand::class,
            \App\Console\Commands\TestActiveCampaignSync::class,
        ];
    }
}