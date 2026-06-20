<?php

use App\Http\Controllers\FreeMedicalAttentionSubscriptionController;
use App\Http\Controllers\MedicalAttentionCheckoutController;
use App\Http\Controllers\MedicalAttentionController;
use App\Http\Controllers\MedicalAttentionPayPalController;
use App\Http\Controllers\MedicalAttentionSubscriptionController;
use Illuminate\Support\Facades\Route;

// Public browsing route
Route::get('/medical-attention', MedicalAttentionController::class)->name('medical-attention');

// Protected routes requiring authentication
Route::middleware([
    'auth',
    'documentation',
    'redirect-incomplete-user',
    'verified',
    'phone-verified',
    'customer',
])->group(function () {
    Route::get('/medical-attention/checkout', MedicalAttentionCheckoutController::class)->name('medical-attention.checkout');
    Route::post('/medical-attention/subscription', MedicalAttentionSubscriptionController::class)->name('medical-attention.subscription');
    Route::post('/medical-attention/paypal/create-order', [MedicalAttentionPayPalController::class, 'createOrder'])->name('medical-attention.paypal.create-order');
    Route::post('/medical-attention/paypal/capture-order', [MedicalAttentionPayPalController::class, 'captureOrder'])->name('medical-attention.paypal.capture-order');
    Route::post('/free-medical-attention/subscription', FreeMedicalAttentionSubscriptionController::class)->name('free-medical-attention.subscription');
});
