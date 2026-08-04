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

/*
|--------------------------------------------------------------------------
| P0-C2 OTP prune — only when otp.p0a.cleanup.enabled is true
|--------------------------------------------------------------------------
|
| Default OFF. Uses withoutOverlapping only (no single-server schedule lock:
| CACHE_STORE may be local/database without a confirmed shared lock store).
| If Forge (or another host) already schedules this command, keep the flag
| OFF here or remove the Forge entry — do not double-run.
| PAT prune remains separate: php artisan sanctum:prune-expired --hours=24
|
*/
if (config('otp.p0a.cleanup.enabled', false)) {
    $scheduleTime = (string) config('otp.p0a.cleanup.schedule_time', '03:00');
    $batch = (int) config('otp.p0a.cleanup.default_batch', 1000);

    Schedule::command("akubica:prune-otp --force --batch={$batch}")
        ->dailyAt($scheduleTime)
        ->withoutOverlapping(120)
        ->name('akubica-prune-otp');
}

/*
|--------------------------------------------------------------------------
| API V1 idempotency prune — only when api_v1.idempotency.prune.enabled
|--------------------------------------------------------------------------
|
| Separate from OTP pruning. Default OFF. withoutOverlapping only
| (no onOneServer — CACHE_STORE may not be a shared lock store).
|
*/
if (config('api_v1.idempotency.prune.enabled', false)) {
    $idempotencyScheduleTime = (string) config('api_v1.idempotency.prune.schedule_time', '04:00');
    $idempotencyBatch = (int) config('api_v1.idempotency.prune.default_batch', 1000);

    Schedule::command("akubica:prune-idempotency --force --batch={$idempotencyBatch}")
        ->dailyAt($idempotencyScheduleTime)
        ->withoutOverlapping(120)
        ->name('akubica-prune-idempotency');
}

if (config('services.activecampaign.coupons_expiring_enabled', false)) {
    Schedule::command('activecampaign:sync-expiring-coupons')
        ->dailyAt('08:00')
        ->withoutOverlapping(30);
}
