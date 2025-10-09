<?php

use App\Http\Controllers\DocumentationAcceptController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceRequests\FiscalCertificateController;
use App\Http\Controllers\LaboratoryPurchasePdfController;
use App\Http\Controllers\PrivacyPolicyController;
use App\Http\Controllers\ResultsController;
use App\Http\Controllers\TermsOfServiceController;
use App\Http\Controllers\VendorPaymentController;
use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)->name('welcome');
Route::get('/terms-of-service', TermsOfServiceController::class)->name('terms-of-service');
Route::get('/privacy-policy', PrivacyPolicyController::class)->name('privacy-policy');
Route::get('/documentation-accept', [DocumentationAcceptController::class, 'index'])->name('documentation.accept');
Route::post('/documentation-accept', [DocumentationAcceptController::class, 'store'])->name('documentation.accept.store');

Route::middleware([
    'auth',
    'documentation',
    'redirect-incomplete-user',
    'verified',
    'phone-verified',
    'customer',
])->group(function () {
    Route::get('/home', HomeController::class)->name('home');
    Route::get('/invoice-requests/{invoice_request}/fiscal-certificate', FiscalCertificateController::class)->name('invoice-requests.fiscal-certificate');
    Route::get('/invoice/{invoice}', InvoiceController::class)->name('invoice');
    Route::get('/vendor-payments/{vendor_payment}', VendorPaymentController::class)->name('vendor-payment');
    Route::get('/laboratory-purchases/{laboratory_purchase}/results', ResultsController::class)->name('laboratory-purchases.results');
    Route::get('/laboratory-purchases/{laboratory_purchase}/download-pdf', [LaboratoryPurchasePdfController::class, 'download'])->name('laboratory-purchases.download-pdf');
    Route::post('/laboratory-purchases/{laboratory_purchase}/email-pdf', [LaboratoryPurchasePdfController::class, 'email'])->name('laboratory-purchases.email-pdf');
});

Route::get('/offline', function () {
    return 'offline';
})->name('offline');

require __DIR__.'/odessa.php';
require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
require __DIR__.'/laboratories.php';
require __DIR__.'/online-pharmacy.php';
require __DIR__.'/medical-attention.php';
require __DIR__.'/auth.php';
