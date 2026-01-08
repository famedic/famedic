<?php

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

// Ruta protegida para obtener el estado de una transacción Efevoo Pay
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/transactions/{transaction}/status', function (Transaction $transaction) {
        return response()->json([
            'status' => $transaction->gateway_status ?? 'pending',
            'transaction_id' => $transaction->id,
            'created_at' => $transaction->created_at,
            'updated_at' => $transaction->updated_at,
        ]);
    });
});