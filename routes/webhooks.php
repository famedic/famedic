<?php
// routes/webhooks.php

use App\Http\Controllers\PayPalController;
use App\Http\Controllers\WebHook\GDAController;
use App\Http\Controllers\Webhooks\Zoho\SalesIqConversationClosedWebhookController;
use App\Http\Controllers\Webhooks\Zoho\SalesIqEventWebhookController;
use App\Http\Controllers\Webhooks\Zoho\SalesIqHandoffWebhookController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

Route::post('/paypal/webhook', [PayPalController::class, 'webhook'])->name('paypal.webhook');

// ==================================================
// Zoho SalesIQ (Fase 4) — secret via X-Famedic-Zoho-Secret
// ==================================================

Route::prefix('webhooks/zoho/salesiq')
    ->middleware(['zoho.salesiq.webhook', 'throttle:60,1'])
    ->group(function () {
        Route::post('/events', SalesIqEventWebhookController::class)
            ->name('webhooks.zoho.salesiq.events');

        Route::post('/handoff', SalesIqHandoffWebhookController::class)
            ->name('webhooks.zoho.salesiq.handoff');

        Route::post('/conversation-closed', SalesIqConversationClosedWebhookController::class)
            ->name('webhooks.zoho.salesiq.conversation-closed');
    });

// ==================================================
// RUTAS GDA - SOLO EN ESTE ARCHIVO
// ==================================================

Route::prefix('apigda')->group(function () {
    // ✅ ESTAS son las únicas rutas GDA que deben existir
    Route::post('/webhook/notification', [GDAController::class, 'saveNotification'])
        ->name('gda.webhook.notification');

    Route::post('/webhook/results', [GDAController::class, 'handleResults'])
        ->name('gda.webhook.results');
});

// ==================================================
// RUTAS DE PRUEBA
// ==================================================

Route::post('/test-gda-simple', function (Request $request) {
    \Illuminate\Support\Facades\File::put(
        storage_path('logs/test_gda_simple.txt'),
        "✅ TEST GDA SIMPLE FUNCIONA!\n" .
        "Time: " . now()->toDateTimeString() . "\n" .
        "Data: " . json_encode($request->all()) . "\n\n"
    );
    
    return response()->json([
        'status' => 'success',
        'message' => '✅ Test GDA simple funcionando',
        'data' => $request->all(),
        'timestamp' => now()->toISOString()
    ]);
});

// ✅ RUTA ALTERNATIVA - POR SI ACASO
Route::post('/gda-emergency/notification', function (Request $request) {
    \Illuminate\Support\Facades\Log::info('🆘 GDA EMERGENCY ROUTE HIT', $request->all());
    
    \Illuminate\Support\Facades\File::put(
        storage_path('logs/gda_emergency_working.txt'),
        "🆘 GDA EMERGENCY ROUTE WORKING!\n" .
        "Time: " . now()->toDateTimeString() . "\n" .
        "Data: " . json_encode($request->all()) . "\n\n"
    );
    
    return response()->json([
        'status' => 'emergency_success',
        'message' => '🆘 GDA Emergency route working!',
        'data' => $request->all(),
        'timestamp' => now()->toISOString()
    ]);
});