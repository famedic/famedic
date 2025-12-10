<?php

use App\Http\Controllers\AddressController;
use App\Http\Controllers\Checkout\AddressController as CheckoutAddressController;
use App\Http\Controllers\Checkout\ContactController as CheckoutContactController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\FamilyController;
use App\Http\Controllers\LaboratoryPurchaseController;
use App\Http\Controllers\OnlinePharmacyPurchaseController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\TaxProfileController;
use App\Http\Controllers\TaxProfiles\FiscalCertificateController;
use Illuminate\Support\Facades\Route;

// Ruta temporal para debugging - SIN middlewares
Route::post('/debug/extract-data', [TaxProfileController::class, 'extractDataDebug'])
    ->name('debug.extract-data');

// Grupo principal SIN password.confirm
Route::middleware([
    'auth',
    'documentation',
    'verified',
    'customer',
])->group(function () {
    
    // Rutas que NO requieren password.confirm
    Route::post('/tax-profiles/extract-data', [TaxProfileController::class, 'extractData'])
        ->name('tax-profiles.extract-data')
        ->withoutMiddleware(['password.confirm']); // ← CLAVE: Excluir explícitamente
    
    Route::get('/test-service', [TaxProfileController::class, 'testService'])
        ->name('test.service');
    
    // Otras rutas que no requieren confirmación
    Route::resource('addresses', AddressController::class)->except('show');
    Route::post('checkout/addresses', CheckoutAddressController::class)->name('checkout.addresses.store');
    Route::resource('contacts', ContactController::class)->except('show');
    Route::post('checkout/contacts', CheckoutContactController::class)->name('checkout.contacts.store');
    Route::resource('payment-methods', PaymentMethodController::class)->only(['index', 'create', 'destroy']);
    Route::resource('laboratory-purchases', LaboratoryPurchaseController::class)->only(['index', 'show']);
    Route::resource('online-pharmacy-purchases', OnlinePharmacyPurchaseController::class)->only(['index', 'show']);
});

// Grupo SEPARADO para rutas que SÍ requieren password.confirm
Route::middleware([
    'auth',
    'documentation',
    'verified',
    'customer',
    'password.confirm', // ← Este middleware aplica a TODAS las rutas dentro
])->group(function () {
    
    // Rutas de tax-profiles que requieren confirmación
    Route::resource('tax-profiles', TaxProfileController::class)
        ->except(['show', 'extract-data']);
    
    Route::get('tax-profiles/{tax_profile}/fiscal-certificate', FiscalCertificateController::class)
        ->name('tax-profiles.fiscal-certificate');
});

// Grupo SEPARADO para family que requiere subscription Y password.confirm
Route::middleware([
    'auth',
    'documentation',
    'verified',
    'customer',
    'medical-attention-subscription',
    'password.confirm',
])->prefix('family')->group(function () {
    Route::get('/', [FamilyController::class, 'index'])->name('family.index');
    Route::get('/create', [FamilyController::class, 'create'])->name('family.create');
    Route::post('/', [FamilyController::class, 'store'])->name('family.store');
    Route::get('/{family_account}/edit', [FamilyController::class, 'edit'])->name('family.edit');
    Route::put('/{family_account}', [FamilyController::class, 'update'])->name('family.update');
    Route::delete('/{family_account}', [FamilyController::class, 'destroy'])->name('family.destroy');
});