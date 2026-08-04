<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\TokenController;
use App\Http\Controllers\Api\V1\CartCouponController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\LaboratoryAppointmentController;
use App\Http\Controllers\Api\V1\OrderDocumentDownloadController;
use App\Http\Controllers\Api\V1\OrderInvoiceRequestController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OrderInvoicesSecureLinkController;
use App\Http\Controllers\Api\V1\OrderInvoicesStepUpController;
use App\Http\Controllers\Api\V1\OrderResultsSecureLinkController;
use App\Http\Controllers\Api\V1\OrderResultsStepUpController;
use App\Http\Controllers\Api\V1\SecureDownloadController;
use App\Http\Controllers\Api\V1\UserAddressController;
use App\Http\Controllers\Api\V1\UserContactController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\UserTaxProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — Integración Akubica
|--------------------------------------------------------------------------
| Prefijo final: /api/v1
| Auth: Laravel Sanctum Bearer Token (catálogo laboratorio público)
*/

Route::middleware(['force.json', 'api.correlation', 'api.token.guard'])->name('api.v1.')->group(function () {

    // ── Auth pública ──────────────────────────────────────────────────────
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('login/request-code', [LoginController::class, 'requestCode'])
            ->middleware(['throttle:akubica-otp', 'api.idempotency', 'api.audit'])
            ->name('login.request-code');

        Route::post('login/verify-code', [LoginController::class, 'verifyCode'])
            ->middleware(['throttle:akubica-otp', 'api.audit'])
            ->name('login.verify-code');

        Route::post('login/resend-code', [LoginController::class, 'resendCode'])
            ->middleware(['throttle:akubica-otp', 'api.audit'])
            ->name('login.resend-code');

        Route::post('register', [RegisterController::class, 'store'])
            ->middleware(['throttle:akubica-otp', 'api.idempotency', 'api.audit'])
            ->name('register');

        Route::post('register/verify-code', [RegisterController::class, 'verifyCode'])
            ->middleware(['throttle:akubica-otp', 'api.audit'])
            ->name('register.verify-code');

        Route::post('register/resend-code', [RegisterController::class, 'resendCode'])
            ->middleware(['throttle:akubica-otp', 'api.audit'])
            ->name('register.resend-code');
    });

    // ── Auth: revocar token (solo Sanctum, sin api.customer) ───────────
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::delete('auth/token', [TokenController::class, 'destroy'])
            ->name('auth.token.revoke');
    });

    // ── Secure downloads (opaque token; no Bearer) ────────────────────────
    Route::get('secure-downloads/{token}', [SecureDownloadController::class, 'show'])
        ->where('token', '[A-Fa-f0-9]{64}')
        ->middleware('throttle:60,1')
        ->name('secure-downloads.show');

    // ── Catálogo laboratorio (público, sin Bearer token) ─────────────────
    Route::prefix('catalog')->name('catalog.')->group(function () {
        Route::get('laboratory-brands', [CatalogController::class, 'indexLaboratoryBrands'])
            ->name('laboratory-brands.index');

        Route::get('laboratory-tests', [CatalogController::class, 'indexLaboratoryTests'])
            ->name('laboratory-tests.index');

        Route::get('laboratory-test-categories', [CatalogController::class, 'indexLaboratoryTestCategories'])
            ->name('laboratory-test-categories.index');

        Route::get('laboratory-stores', [CatalogController::class, 'indexLaboratoryStores'])
            ->name('laboratory-stores.index');

        Route::get('laboratory-tests/{laboratory_test_id}', [CatalogController::class, 'showLaboratoryTest'])
            ->name('laboratory-tests.show');

        Route::get('medications/{medication_id}', [CatalogController::class, 'showMedication'])
            ->name('medications.show');
    });

    // ── Rutas protegidas ──────────────────────────────────────────────────
    Route::middleware(['auth:sanctum', 'api.customer'])->group(function () {

        Route::get('cart/totals', [CartController::class, 'totals'])->name('cart.totals');
        Route::get('cart/coupon', [CartCouponController::class, 'show'])->name('cart.coupon.show');
        Route::post('cart/coupon', [CartCouponController::class, 'apply'])->name('cart.coupon.apply');
        Route::delete('cart/coupon', [CartCouponController::class, 'remove'])->name('cart.coupon.remove');
        Route::delete('cart', [CartController::class, 'clear'])->name('cart.clear');
        Route::get('cart', [CartController::class, 'index'])->name('cart.index');
        Route::post('cart/items', [CartController::class, 'store'])->name('cart.items.store');
        Route::delete('cart/items/{cart_item_id}', [CartController::class, 'destroy'])
            ->name('cart.items.destroy');

        Route::get('checkout/prepare', [CheckoutController::class, 'prepare'])->name('checkout.prepare');
        Route::post('checkout/draft', [CheckoutController::class, 'syncDraft'])->name('checkout.draft');
        Route::post('checkout/payment-link', [CheckoutController::class, 'paymentLink'])
            ->middleware('api.idempotency')
            ->name('checkout.payment-link');

        Route::get('laboratory-appointments/requirements', [LaboratoryAppointmentController::class, 'requirements'])
            ->name('laboratory-appointments.requirements');
        Route::get('laboratory-appointments', [LaboratoryAppointmentController::class, 'index'])
            ->name('laboratory-appointments.index');
        Route::post('laboratory-appointments', [LaboratoryAppointmentController::class, 'store'])
            ->middleware('api.idempotency')
            ->name('laboratory-appointments.store');
        Route::delete('laboratory-appointments/{appointment_id}', [LaboratoryAppointmentController::class, 'destroy'])
            ->name('laboratory-appointments.destroy');

        Route::prefix('orders')->name('orders.')->group(function () {
            Route::get('/', [OrderController::class, 'index'])->name('index');
            Route::get('results', [OrderController::class, 'resultsIndex'])->name('results.index');
            Route::get('invoices', [OrderController::class, 'invoicesIndex'])->name('invoices.index');

            Route::get('{order_id}/results/download', [OrderDocumentDownloadController::class, 'downloadResult'])
                ->name('results.download');
            Route::get('{order_id}/invoices/{invoice_id}/download', [OrderDocumentDownloadController::class, 'downloadInvoice'])
                ->name('invoices.download');

            Route::post('{order_id}/invoices/{invoice_id}/step-up/request', [OrderInvoicesStepUpController::class, 'request'])
                ->middleware(['throttle:akubica-otp', 'api.idempotency', 'api.audit'])
                ->name('invoices.step-up.request');
            Route::post('{order_id}/invoices/{invoice_id}/step-up/verify', [OrderInvoicesStepUpController::class, 'verify'])
                ->middleware(['throttle:akubica-otp', 'api.audit'])
                ->name('invoices.step-up.verify');
            Route::post('{order_id}/invoices/{invoice_id}/secure-link', [OrderInvoicesSecureLinkController::class, 'store'])
                ->middleware(['throttle:60,1', 'api.idempotency'])
                ->name('invoices.secure-link');

            Route::post('{order_id}/results/step-up/request', [OrderResultsStepUpController::class, 'request'])
                ->middleware(['throttle:akubica-otp', 'api.idempotency', 'api.audit'])
                ->name('results.step-up.request');
            Route::post('{order_id}/results/step-up/verify', [OrderResultsStepUpController::class, 'verify'])
                ->middleware(['throttle:akubica-otp', 'api.audit'])
                ->name('results.step-up.verify');
            Route::post('{order_id}/results/secure-link', [OrderResultsSecureLinkController::class, 'store'])
                ->middleware(['throttle:60,1', 'api.idempotency'])
                ->name('results.secure-link');

            Route::get('{order_id}/invoice-request/status', [OrderInvoiceRequestController::class, 'status'])
                ->name('invoice-request.status');
            Route::post('{order_id}/invoice-request', [OrderInvoiceRequestController::class, 'store'])
                ->middleware('api.idempotency')
                ->name('invoice-request.store');

            Route::get('{order_id}/products', [OrderController::class, 'products'])->name('products');
            Route::get('{order_id}/invoices', [OrderController::class, 'invoices'])->name('invoices');
            Route::get('{order_id}/results', [OrderController::class, 'results'])->name('results');
            Route::get('{order_id}/status', [OrderController::class, 'status'])->name('status');
            Route::put('{order_id}/cancel', [OrderController::class, 'cancel'])->name('cancel');
        });

        Route::prefix('user')->name('user.')->group(function () {
            Route::get('family', [UserController::class, 'family'])->name('family');
            Route::get('tax-profiles', [UserController::class, 'taxProfiles'])->name('tax-profiles');
            Route::post('tax-profiles', [UserTaxProfileController::class, 'store'])->name('tax-profiles.store');
            Route::put('tax-profiles/{tax_profile_id}', [UserTaxProfileController::class, 'update'])->name('tax-profiles.update');
            Route::delete('tax-profiles/{tax_profile_id}', [UserTaxProfileController::class, 'destroy'])->name('tax-profiles.destroy');
            Route::get('addresses', [UserController::class, 'addresses'])->name('addresses');
            Route::post('addresses', [UserAddressController::class, 'store'])->name('addresses.store');
            Route::put('addresses/{address_id}', [UserAddressController::class, 'update'])->name('addresses.update');
            Route::delete('addresses/{address_id}', [UserAddressController::class, 'destroy'])->name('addresses.destroy');
            Route::get('payment-methods', [UserController::class, 'paymentMethods'])->name('payment-methods');

            Route::get('contacts', [UserContactController::class, 'index'])->name('contacts.index');
            Route::post('contacts', [UserContactController::class, 'store'])->name('contacts.store');
            Route::put('contacts/{contact_id}', [UserContactController::class, 'update'])->name('contacts.update');
            Route::delete('contacts/{contact_id}', [UserContactController::class, 'destroy'])->name('contacts.destroy');
        });
    });
});
