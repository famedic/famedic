<?php

use App\Http\Controllers\DocumentationAcceptController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceRequests\FiscalCertificateController;
use App\Http\Controllers\LaboratoryPurchasePdfController;
use App\Http\Controllers\PrivacyPolicyController;
use App\Http\Controllers\ResultsController;
use App\Http\Controllers\TermsOfServiceController;
use App\Http\Controllers\DocumentsServiceController;
use App\Http\Controllers\VendorPaymentController;
use App\Http\Controllers\WelcomeController;
use App\Http\Controllers\PaymentMethodController;
use App\Services\EfevooPayService;
//use App\Http\Controllers\WebHook\GDAWebHookController;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)->name('welcome');
#Route::get('/terms-of-service', TermsOfServiceController::class)->name('terms-of-service');
#Route::get('/privacy-policy', PrivacyPolicyController::class)->name('privacy-policy');
Route::get('/documentation-accept', [DocumentationAcceptController::class, 'index'])->name('documentation.accept');
Route::post('/documentation-accept', [DocumentationAcceptController::class, 'store'])->name('documentation.accept.store');

// Rutas de documentos de servicio (TOS, Privacy Policy, ARCO)
Route::get('/terms-of-service', [DocumentsServiceController::class, 'termsOfService'])->name('terms-of-service');
Route::get('/privacy-policy', [DocumentsServiceController::class, 'privacyPolicy'])->name('privacy-policy');
//Route::get('/rights-arco', [DocumentsServiceController::class, 'rightsARCO'])->name('rights-arco');

// Derechos ARCO
Route::get('/derechos-arco', [DocumentsServiceController::class, 'rightsARCO'])->name('rights-arco');
Route::post('/derechos-arco', [DocumentsServiceController::class, 'storeARCO'])->name('store-arco');
Route::get('/derechos-arco/exito', [DocumentsServiceController::class, 'successARCO'])->name('arco-success')->middleware('web');
Route::get('/mis-solicitudes-arco', [DocumentsServiceController::class, 'misSolicitudes'])->name('mis-solicitudes-arco')->middleware('auth');
Route::get('/solicitud-arco/{id}', [DocumentsServiceController::class, 'verSolicitud'])->name('ver-solicitud-arco')->middleware('auth');
Route::get('/descargar-documento/{solicitudId}/{tipo}', [DocumentsServiceController::class, 'descargarDocumento'])->name('descargar-documento-arco')->middleware('auth');

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
require __DIR__.'/webhooks.php';

Route::get('/auth-debug', function () {
    return [
        'auth_check' => auth()->check(),
        'user' => auth()->user(),
        'session_id' => session()->getId(),
        'cookie_session' => request()->cookie(config('session.cookie')),
    ];
});