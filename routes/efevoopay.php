<?php

use App\Http\Controllers\EfevooPay\EfevooPayWebsocketController;
use App\Http\Controllers\EfevooPay\EfevooPayController;
use App\Http\Controllers\EfevooPay\PaymentMethodCardController;
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

// Payment Methods Routes
Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('payment-methods', PaymentMethodCardController::class)
        ->except(['show']);
    
    Route::post('/payment-methods/{payment_method}/default', 
        [PaymentMethodCardController::class, 'setAsDefault'])
        ->name('payment-methods.set-default');
});

// API Routes (si necesitas para React)
Route::middleware(['auth:sanctum'])->prefix('api')->group(function () {
    Route::get('/payment-methods', 
        [PaymentMethodCardController::class, 'apiIndex']);
    Route::get('/payment-methods/{payment_method}', 
        [PaymentMethodCardController::class, 'apiShow']);
});