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
