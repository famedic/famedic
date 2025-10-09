<?php

use App\Http\Controllers\FreeMedicalAttentionSubscriptionController;
use App\Http\Controllers\MedicalAttentionController;
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
    Route::post('/medical-attention/subscription', MedicalAttentionSubscriptionController::class)->name('medical-attention.subscription');
    Route::post('/free-medical-attention/subscription', FreeMedicalAttentionSubscriptionController::class)->name('free-medical-attention.subscription');
});
