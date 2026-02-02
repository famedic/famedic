<?php

use App\Http\Controllers\Api\EfevooPayController;

use App\Http\Controllers\TestApiController;
use App\Http\Controllers\LaboratoryEndpointController;
use App\Http\Controllers\Laboratory\LaboratoryWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/test', [TestApiController::class, 'test']);
Route::apiResource('test-items', TestApiController::class);

Route::get('/endpoint/{id}', [LaboratoryEndpointController::class, 'show']);

// Rutas públicas para testing del laboratorio
Route::get('/laboratory/test', [LaboratoryEndpointController::class, 'test']);
Route::get('/laboratory/create-test', [LaboratoryEndpointController::class, 'createTest']);
Route::apiResource('laboratory/notifications', LaboratoryEndpointController::class);

// Webhooks del laboratorio (GDA)
Route::prefix('laboratory/webhook')->name('laboratory.webhook.')->group(function () {
    // Health check
    Route::get('health', [LaboratoryWebhookController::class, 'healthCheck'])
        ->name('health');
    
    // Endpoint de pruebas
    Route::post('test', [LaboratoryWebhookController::class, 'testWebhook'])
        ->name('test');
    
    // Webhook principal (GDA)
    Route::post('notifications', [LaboratoryWebhookController::class, 'handleNotification'])
        ->name('notifications');
});

// Rutas para EfevooPay
Route::prefix('efevoopay')->group(function () {
    // Health check
    Route::get('health', [EfevooPayController::class, 'healthCheck']);
    
    // Tokenización y manejo de tokens
    Route::post('tokenize', [EfevooPayController::class, 'tokenizeCard']);
    Route::get('tokens', [EfevooPayController::class, 'getUserTokens']);
    Route::delete('tokens/{token}', [EfevooPayController::class, 'deleteToken']);
    
    // Pagos y reembolsos
    Route::post('payment', [EfevooPayController::class, 'processPayment']);
    Route::post('refund', [EfevooPayController::class, 'refund']);
    
    // Consultas
    Route::post('transactions/search', [EfevooPayController::class, 'searchTransactions']);
});