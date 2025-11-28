<?php

use App\Http\Controllers\AddressController;
use App\Http\Controllers\Checkout\AddressController as CheckoutAddressController;
use App\Http\Controllers\Checkout\ContactController as CheckoutContactController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\FamilyController;
use App\Http\Controllers\LaboratoryPurchaseController;
use App\Http\Controllers\LaboratoryResultController;
use App\Http\Controllers\LaboratoryQuoteController;

use App\Http\Controllers\OnlinePharmacyPurchaseController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\TaxProfileController;
use App\Http\Controllers\TaxProfiles\FiscalCertificateController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth',
    'documentation',
    'verified',
    'customer',
])->group(function () {
    Route::resource('addresses', AddressController::class)->except('show');
    Route::post('checkout/addresses', CheckoutAddressController::class)->name('checkout.addresses.store');
    Route::resource('contacts', ContactController::class)->except('show');
    Route::post('checkout/contacts', CheckoutContactController::class)->name('checkout.contacts.store');
    Route::resource('payment-methods', PaymentMethodController::class)->only(['index', 'create', 'destroy']);
    Route::resource('laboratory-purchases', LaboratoryPurchaseController::class)->only(['index', 'show']);
    
    Route::resource('laboratory-quotes', LaboratoryQuoteController::class)->only(['index', 'show']);
    Route::resource('laboratory-results', LaboratoryResultController::class)->only(['index', 'show']);

    Route::resource('online-pharmacy-purchases', OnlinePharmacyPurchaseController::class)->only(['index', 'show']);
    Route::resource('tax-profiles', TaxProfileController::class)->except(['show'])->middleware('password.confirm');
    Route::get('tax-profiles/{tax_profile}/fiscal-certificate', FiscalCertificateController::class)->name('tax-profiles.fiscal-certificate')->middleware('password.confirm');

    Route::get('family', [FamilyController::class, 'index'])
        ->middleware(['medical-attention-subscription', 'password.confirm'])
        ->name('family.index');

    Route::get('family/create', [FamilyController::class, 'create'])
        ->middleware(['medical-attention-subscription', 'password.confirm'])
        ->name('family.create');

    Route::post('family', [FamilyController::class, 'store'])
        ->middleware(['medical-attention-subscription', 'password.confirm'])
        ->name('family.store');

    Route::get('family/{family_account}/edit', [FamilyController::class, 'edit'])
        ->middleware(['medical-attention-subscription', 'password.confirm'])
        ->name('family.edit');

    Route::put('family/{family_account}', [FamilyController::class, 'update'])
        ->middleware(['medical-attention-subscription', 'password.confirm'])
        ->name('family.update');

    Route::delete('family/{family_account}', [FamilyController::class, 'destroy'])
        ->middleware(['medical-attention-subscription', 'password.confirm'])
        ->name('family.destroy');
});
