<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

if (config('services.activecampaign.tag_abandoned_carts_enabled', true)) {
    Schedule::command('activecampaign:tag-abandoned-carts')
        ->everyFifteenMinutes()
        ->withoutOverlapping(10);
}

if (config('services.activecampaign.coupons_expiring_enabled', false)) {
    Schedule::command('activecampaign:sync-expiring-coupons')
        ->dailyAt('08:00')
        ->withoutOverlapping(30);
}

Schedule::command('odessa:prune-pre-enrollment-import-runs')
    ->dailyAt('02:30')
    ->withoutOverlapping(30);

/*
| Fase 5B — GC sesiones abandonadas con 3ds_card_data_* (solo SESSION_DRIVER=database).
| Dry-run cada 5 min para métricas sanitizadas. NO habilitar --apply hasta aprobación operativa.
|
| Schedule::command('efevoopay:purge-expired-card-session-data')
|     ->everyFiveMinutes()
|     ->withoutOverlapping(4)
|     ->onOneServer();
|
| Tras validación en staging:
| Schedule::command('efevoopay:purge-expired-card-session-data --apply')
|     ->everyFiveMinutes()
|     ->withoutOverlapping(4)
|     ->onOneServer();
*/
