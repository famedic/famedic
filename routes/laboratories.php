<?php

use App\Http\Controllers\Laboratories\LaboratoryBrandSelectionController;
use App\Http\Controllers\Laboratories\LaboratoryPurchases\InvoiceRequestController;
use App\Http\Controllers\Laboratories\LaboratoryTestsController;
use App\Http\Controllers\LaboratoryAppointmentController;
use App\Http\Controllers\LaboratoryCartItemController;
use App\Http\Controllers\LaboratoryCheckoutController;
use App\Http\Controllers\LaboratoryPurchaseController;
use App\Http\Controllers\LaboratoryQuoteController;
use App\Http\Controllers\LaboratoryResultsController;
use App\Http\Controllers\LaboratoryShoppingCartController;
use App\Http\Controllers\LaboratoryStoreController;
use App\Http\Controllers\LabResultsAccessController;
use App\Http\Controllers\LaboratoryResultsOtpController;
use App\Http\Middleware\EnsureLabResultsOtpVerified;
use Illuminate\Support\Facades\Route;

// Public browsing routes
Route::get('/laboratory-brand-selection', LaboratoryBrandSelectionController::class)->name('laboratory-brand-selection');
Route::get('/laboratory/{laboratory_brand}/laboratory-tests', [LaboratoryTestsController::class, 'index'])->name('laboratory-tests');
Route::get('/laboratory-tests/{laboratory_test}', [LaboratoryTestsController::class, 'show'])->name('laboratory-tests.test');
Route::resource('laboratory-stores', LaboratoryStoreController::class)->only(['index']);
$labResultsThrottle = 'throttle:'.config('laboratory-results.rate_limit_per_minute', 12).',1';

Route::get('/lab-results/{token}', [LabResultsAccessController::class, 'show'])->name('lab-results.show');

Route::get('/lab-results/shared/{token}', [LabResultsAccessController::class, 'showShared'])
    ->middleware('signed')
    ->name('lab-results.show-shared');

Route::get('/lab-results/shared/{token}/pdf', [LabResultsAccessController::class, 'streamSharedPdf'])
    ->middleware('signed')
    ->name('lab-results.shared-pdf');

Route::middleware([$labResultsThrottle])->group(function () {
    Route::post('/lab-results/send-otp', [LabResultsAccessController::class, 'sendOtp'])->name('lab-results.send-otp');
    Route::post('/lab-results/verify', [LabResultsAccessController::class, 'verify'])->name('lab-results.verify');
    Route::post('/lab-results/resend', [LabResultsAccessController::class, 'resend'])->name('lab-results.resend');
});

Route::get('/lab-results/{token}/pdf', [LabResultsAccessController::class, 'streamPdf'])
    ->middleware(['signed', 'throttle:60,1'])
    ->name('lab-results.pdf');

// Protected routes requiring authentication
Route::middleware([
    'auth',
    'documentation',
    'redirect-incomplete-user',
    'verified',
    'phone-verified',
    'customer',
])->group(function () {
    Route::middleware(['throttle:12,1'])->group(function () {
        Route::get('/otp/status/{laboratory_purchase}', [LaboratoryResultsOtpController::class, 'status'])->name('otp.status');
        Route::post('/otp/send/{laboratory_purchase}', [LaboratoryResultsOtpController::class, 'send'])->name('otp.send');
        Route::post('/otp/resend/{laboratory_purchase}', [LaboratoryResultsOtpController::class, 'resend'])->name('otp.resend');
        Route::post('/otp/verify/{laboratory_purchase}', [LaboratoryResultsOtpController::class, 'verify'])->name('otp.verify');
    });

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

    // Request for quotations
    Route::post('/{laboratory_brand}/quote', [LaboratoryQuoteController::class, 'store'])
        ->name('api.laboratory.quote.store');

    // Route get quote success
    Route::get('/laboratory/quote/{quote}', [LaboratoryQuoteController::class, 'success'])
        ->name('laboratory.quote.show');
    /*
    // Laboratory Results
    Route::get('/mis-resultados', [LaboratoryResultController::class, 'index'])
        ->name('patient.results');

    // Marcar como descargado (llamado desde el frontend)
    Route::post('/api/lab-results/{resultId}/mark-downloaded', [LaboratoryResultController::class, 'markAsDownloaded'])
        ->name('patient.results.mark-downloaded');
    */

    // Route::get('/laboratory-results', [LaboratoryResultController::class, 'index'])->name('laboratory-results.index');

    // Route::get('/laboratory-results/{type}/{id}/download', [LaboratoryResultController::class, 'download'])->name('laboratory-results.download');
    // Route::get('/laboratory-results/{type}/{id}/view', [LaboratoryResultController::class, 'view'])->name('laboratory-results.view');

    Route::post('/laboratory-results/notification/{notification}/mark-read', [LaboratoryResultController::class, 'markAsRead']);

    Route::prefix('laboratory-results')->group(function () {
        Route::get('/', [LaboratoryResultController::class, 'index'])->name('laboratory-results.index');
        Route::post('/notification/{notification}/mark-read', [LaboratoryResultController::class, 'markAsRead'])->name('laboratory-results.mark-read');
        Route::post('/notification/{notification}/refresh', [LaboratoryResultController::class, 'refreshResults'])->name('laboratory-results.refresh');
        Route::get('/{type}/{id}/view', [LaboratoryResultController::class, 'view'])
            ->middleware(EnsureLabResultsOtpVerified::class)
            ->name('laboratory-results.view');
        Route::get('/{type}/{id}/download', [LaboratoryResultController::class, 'download'])
            ->middleware(EnsureLabResultsOtpVerified::class)
            ->name('laboratory-results.download');

        Route::get('/debug/{notificationId}', [LaboratoryResultController::class, 'debugNotification'])->name('laboratory-results.debug');
    });

    // Route for fetching results via AJAX
    Route::post(
        '/laboratory-purchases/{laboratoryPurchase}/results-automatic-fetch',
        [LaboratoryResultsController::class, 'fetch']
    )->middleware(EnsureLabResultsOtpVerified::class)
        ->name('laboratory-purchases.results.automatic-fetch');
});
