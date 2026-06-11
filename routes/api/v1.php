<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\TokenController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — Integración Akubica
|--------------------------------------------------------------------------
| Prefijo final: /api/v1
| Auth: Laravel Sanctum Bearer Token
*/

Route::middleware(['force.json', 'api.token.guard'])->name('api.v1.')->group(function () {

    // ── Auth pública ──────────────────────────────────────────────────────
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('login/request-code', [LoginController::class, 'requestCode'])
            ->middleware('throttle:akubica-otp')
            ->name('login.request-code');

        Route::post('login/verify-code', [LoginController::class, 'verifyCode'])
            ->middleware('throttle:akubica-otp')
            ->name('login.verify-code');

        Route::post('register', [RegisterController::class, 'store'])
            ->middleware('throttle:akubica-otp')
            ->name('register');

        Route::post('register/verify-code', [RegisterController::class, 'verifyCode'])
            ->middleware('throttle:akubica-otp')
            ->name('register.verify-code');
    });

    // ── Auth: revocar token (solo Sanctum, sin api.customer) ───────────
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::delete('auth/token', [TokenController::class, 'destroy'])
            ->name('auth.token.revoke');
    });

    // ── Rutas protegidas ──────────────────────────────────────────────────
    Route::middleware(['auth:sanctum', 'api.customer'])->group(function () {

        Route::prefix('catalog')->name('catalog.')->group(function () {
            Route::get('laboratory-tests/{laboratory_test_id}', [CatalogController::class, 'showLaboratoryTest'])
                ->name('laboratory-tests.show');

            Route::get('medications/{medication_id}', [CatalogController::class, 'showMedication'])
                ->name('medications.show');
        });

        Route::get('cart', [CartController::class, 'index'])->name('cart.index');
        Route::post('cart/items', [CartController::class, 'store'])->name('cart.items.store');
        Route::delete('cart/items/{cart_item_id}', [CartController::class, 'destroy'])
            ->name('cart.items.destroy');

        Route::prefix('orders')->name('orders.')->group(function () {
            Route::get('/', [OrderController::class, 'index'])->name('index');
            Route::get('results', [OrderController::class, 'resultsIndex'])->name('results.index');
            Route::get('invoices', [OrderController::class, 'invoicesIndex'])->name('invoices.index');

            Route::get('{order_id}/products', [OrderController::class, 'products'])->name('products');
            Route::get('{order_id}/invoices', [OrderController::class, 'invoices'])->name('invoices');
            Route::get('{order_id}/results', [OrderController::class, 'results'])->name('results');
            Route::get('{order_id}/status', [OrderController::class, 'status'])->name('status');
            Route::put('{order_id}/cancel', [OrderController::class, 'cancel'])->name('cancel');
        });

        Route::prefix('user')->name('user.')->group(function () {
            Route::get('family', [UserController::class, 'family'])->name('family');
            Route::get('tax-profiles', [UserController::class, 'taxProfiles'])->name('tax-profiles');
            Route::get('addresses', [UserController::class, 'addresses'])->name('addresses');
            Route::get('payment-methods', [UserController::class, 'paymentMethods'])->name('payment-methods');
        });
    });
});
