<?php

use App\Http\Controllers\EfevooPayWebsocketController;
use App\Http\Controllers\EfevooPayController;
#use App\Http\Controllers\LaboratoryEndpointController;
#use App\Http\Controllers\Laboratory\LaboratoryWebhookController;
use Illuminate\Support\Facades\Route;


// EfevooPay Routes
Route::middleware(['auth', 'verified'])->group(function () {
    // Crear checkout
    Route::post('/efevoopay/checkout', [EfevooPayController::class, 'createCheckout'])
        ->name('efevoopay.checkout.create');
    
    // Callback después del pago
    Route::get('/efevoopay/callback', [EfevooPayController::class, 'callback'])
        ->name('efevoopay.callback');
    
    // Verificar estado de transacción
    Route::get('/efevoopay/transaction/{transaction}/status', 
        [EfevooPayController::class, 'checkTransactionStatus'])
        ->name('efevoopay.transaction.status');
    
    // WebSocket authentication
    Route::post('/broadcasting/auth', 
        [EfevooPayWebsocketController::class, 'authenticateChannel'])
        ->name('broadcasting.auth');
    
    // Obtener canales del usuario
    Route::get('/api/websocket/channels', 
        [EfevooPayWebsocketController::class, 'getChannels'])
        ->name('api.websocket.channels');
});

// Webhook para notificaciones de EfevooPay (sin autenticación)
Route::post('/webhook/efevoopay', [EfevooPayController::class, 'webhook'])
    ->name('webhook.efevoopay');

// Endpoint para notificaciones WebSocket simuladas
Route::post('/api/efevoopay/notification', 
    [EfevooPayWebsocketController::class, 'handleNotification'])
    ->middleware('api')
    ->name('api.efevoopay.notification');