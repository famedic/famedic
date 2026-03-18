<?php

use App\Http\Controllers\OnlinePharmacy\OnlinePharmacyPurchases\InvoiceRequestController;
use App\Http\Controllers\OnlinePharmacyCartItemController;
use App\Http\Controllers\OnlinePharmacyCheckoutController;
use App\Http\Controllers\OnlinePharmacyController;
use App\Http\Controllers\OnlinePharmacyPurchaseController;
use App\Http\Controllers\OnlinePharmacySearchController;
use App\Http\Controllers\OnlinePharmacyShoppingCartController;
use Illuminate\Support\Facades\Route;

// Public browsing routes
Route::get('/online-pharmacy', function () {
    return redirect()
        ->route('welcome')
        ->with('warning', 'La farmacia en línea está temporalmente deshabilitada.');
})->name('online-pharmacy');

Route::get('/online-pharmacy/search', function () {
    return redirect()
        ->route('welcome')
        ->with('warning', 'La farmacia en línea está temporalmente deshabilitada.');
})->name('online-pharmacy-search');

// Protected routes requiring authentication
Route::middleware([
    'auth',
    'documentation',
    'redirect-incomplete-user',
    'verified',
    'phone-verified',
    'customer',
])->group(function () {
    // Shopping Cart & Checkout
    Route::resource('online-pharmacy-cart-items', OnlinePharmacyCartItemController::class)->only(['store', 'update', 'destroy']);
    Route::get('/online-pharmacy/shopping-cart', OnlinePharmacyShoppingCartController::class)->name('online-pharmacy.shopping-cart');

    Route::get('/online-pharmacy/checkout', OnlinePharmacyCheckoutController::class)
        ->name('online-pharmacy.checkout')
        ->middleware('redirect-if-empty-online-pharmacy-cart-items');

    Route::post('/online-pharmacy/checkout', [OnlinePharmacyPurchaseController::class, 'store'])
        ->name('online-pharmacy.checkout.store')
        ->middleware('redirect-if-empty-online-pharmacy-cart-items');

    // Invoice requests
    Route::post('/online-pharmacy-purchases/{online_pharmacy_purchase}/invoice-request', InvoiceRequestController::class)->name('online-pharmacy-purchases.invoice-request');
});
