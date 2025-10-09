<?php

use App\Http\Controllers\Auth\OdessaRegisterController;
use App\Http\Controllers\Odessa\OdessaController;
use App\Http\Controllers\Odessa\OdessaLinkAuthSelectionController;
use App\Http\Controllers\Odessa\OdessaUpgradeController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/odessa/{odessa_token}', [OdessaController::class, 'index'])->name('odessa.index');
    Route::get('/odessa-register/{odessa_token}', [OdessaRegisterController::class, 'index'])->name('odessa-register.index');
    Route::post('/odessa-register/{odessa_token}', [OdessaRegisterController::class, 'store'])->name('odessa-register.store');
    // Route::get('/odessa-no-access', OdessaNoAccessController::class)->name('odessa-no-access.index');
    Route::get('/odessa-link-auth-selection/{odessa_token}', [OdessaLinkAuthSelectionController::class, 'index'])->name('odessa-link-auth-selection.index');
    // Route::get('/odessa-login/{odessa_token}', [OdessaLoginController::class, 'index'])->name('odessa-login.index');
    // Route::post('/odessa-login/{odessa_token}', [OdessaLoginController::class, 'store'])->name('odessa-login.store');
});

Route::middleware([
    'auth',
    'verified',
    'phone-verified',
    'customer',
])->group(function () {
    Route::get('/odessa-upgrade/{odessa_token}', [OdessaUpgradeController::class, 'index'])->name('odessa-upgrade.index');
    Route::post('/odessa-upgrade/{odessa_token}', [OdessaUpgradeController::class, 'store'])->name('odessa-upgrade.store');
});
