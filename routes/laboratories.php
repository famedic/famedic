<?php

use App\Http\Controllers\Laboratories\LaboratoryBrandSelectionController;
use App\Http\Controllers\Laboratories\LaboratoryPurchases\InvoiceRequestController;
use App\Http\Controllers\Laboratories\LaboratoryTestsController;
use App\Http\Controllers\LaboratoryAppointmentController;
use App\Http\Controllers\LaboratoryCartItemController;
use App\Http\Controllers\LaboratoryCheckoutController;
use App\Http\Controllers\LaboratoryPurchaseController;
use App\Http\Controllers\LaboratoryShoppingCartController;
use App\Http\Controllers\LaboratoryStoreController;
use Illuminate\Support\Facades\Route;

// Public browsing routes
Route::get('/laboratory-brand-selection', LaboratoryBrandSelectionController::class)->name('laboratory-brand-selection');
Route::get('/laboratory/{laboratory_brand}/laboratory-tests', [LaboratoryTestsController::class, 'index'])->name('laboratory-tests');
Route::get('/laboratory-tests/{laboratory_test}', [LaboratoryTestsController::class, 'show'])->name('laboratory-tests.test');
Route::resource('laboratory-stores', LaboratoryStoreController::class)->only(['index']);

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
    Route::resource('laboratory-cart-items', LaboratoryCartItemController::class)->only(['store', 'destroy']);
    Route::get('/laboratory/{laboratory_brand}/shopping-cart', LaboratoryShoppingCartController::class)->name('laboratory.shopping-cart');

    Route::get('/laboratory/{laboratory_brand}/checkout', LaboratoryCheckoutController::class)
        ->name('laboratory.checkout')
        ->middleware('laboratory-appointment', 'redirect-if-empty-laboratory-cart-items');

    Route::post('/laboratory/{laboratory_brand}/checkout', [LaboratoryPurchaseController::class, 'store'])
        ->name('laboratory.checkout.store')
        ->middleware('laboratory-appointment', 'redirect-if-empty-laboratory-cart-items');

    // Appointments
    Route::get('/{laboratory_brand}/laboratory-appointments/create', [LaboratoryAppointmentController::class, 'create'])
        ->name('laboratory-appointments.create')
        ->middleware('no-duplicate-laboratory-appointment');

    Route::post('/{laboratory_brand}/laboratory-appointments', [LaboratoryAppointmentController::class, 'store'])
        ->name('laboratory-appointments.store')
        ->middleware('no-duplicate-laboratory-appointment');

    Route::get('/{laboratory_brand}/laboratory-appointments/{laboratory_appointment}', [LaboratoryAppointmentController::class, 'show'])
        ->name('laboratory-appointments.show')
        ->middleware('redirect-if-appointment-confirmed');

    Route::delete('/laboratory-appointments/{laboratoryAppointment}', [LaboratoryAppointmentController::class, 'destroy'])
        ->name('laboratory-appointments.destroy');

    // Invoice requests
    Route::post('/laboratory-purchases/{laboratory_purchase}/invoice-request', InvoiceRequestController::class)->name('laboratory-purchases.invoice-request');
});
