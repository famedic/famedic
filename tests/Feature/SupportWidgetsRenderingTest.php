<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

beforeEach(function () {
    $this->withoutVite();

    config(['famedic.support_widgets.enabled' => true]);

    Route::middleware('web')->get('/support-widget-probe', fn () => Inertia::render('Welcome'));
    Route::middleware('web')->get('/admin/support-widget-probe', fn () => Inertia::render('Admin/Admin'));
});

test('support widgets render on public routes when enabled', function () {
    $this->get('/support-widget-probe')
        ->assertOk()
        ->assertSee('window.__FAMEDIC_SUPPORT_WIDGETS__', false)
        ->assertSee('shouldRender: true', false)
        ->assertSee('diffuser-cdn.app-us1.com/whatsapp/widget.cjs.production.min.js', false);
});

test('support widgets do not render on admin routes', function () {
    $this->get('/admin/support-widget-probe')
        ->assertOk()
        ->assertSee('window.__FAMEDIC_SUPPORT_WIDGETS__', false)
        ->assertSee('shouldRender: false', false)
        ->assertDontSee('diffuser-cdn.app-us1.com/whatsapp/widget.cjs.production.min.js', false);
});

test('laboratory checkout routes are allowed to render ActiveCampaign support widgets', function () {
    $blade = file_get_contents(resource_path('views/app.blade.php'));

    expect($blade)->not->toContain("'laboratory.checkout'");
    expect($blade)->toContain("'online-pharmacy.checkout'");
    expect($blade)->toContain("'medical-attention.checkout'");
});
