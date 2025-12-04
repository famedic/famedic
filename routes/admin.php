<?php

use App\Http\Controllers\Admin\AdministratorController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DocumentationController;
use App\Http\Controllers\Admin\LaboratoryAppointmentController;
use App\Http\Controllers\Admin\LaboratoryPurchaseController;
use App\Http\Controllers\Admin\LaboratoryPurchases\DevAssistanceRequestController as LaboratoryDevAssistanceRequestController;
use App\Http\Controllers\Admin\LaboratoryPurchases\InvoiceController;
use App\Http\Controllers\Admin\LaboratoryPurchases\ResolvedDevAssistanceRequestController as LaboratoryResolvedDevAssistanceRequestController;
use App\Http\Controllers\Admin\LaboratoryPurchases\ResultsController;
use App\Http\Controllers\Admin\LaboratoryPurchases\UnresolvedDevAssistanceRequestController as LaboratoryUnresolvedDevAssistanceRequestController;
use App\Http\Controllers\Admin\LaboratoryPurchases\VendorPaymentsController as LaboratoryVendorPaymentsController;
use App\Http\Controllers\Admin\LaboratoryTestController;
use App\Http\Controllers\Admin\MedicalAttentionSubscriptionController;
use App\Http\Controllers\Admin\OnlinePharmacyPurchaseController;
use App\Http\Controllers\Admin\OnlinePharmacyPurchases\DevAssistanceRequestController as OnlinePharmacyDevAssistanceRequestController;
use App\Http\Controllers\Admin\OnlinePharmacyPurchases\InvoiceController as OnlinePharmacyPurchasesInvoiceController;
use App\Http\Controllers\Admin\OnlinePharmacyPurchases\ResolvedDevAssistanceRequestController as OnlinePharmacyResolvedDevAssistanceRequestController;
use App\Http\Controllers\Admin\OnlinePharmacyPurchases\UnresolvedDevAssistanceRequestController as OnlinePharmacyUnresolvedDevAssistanceRequestController;
use App\Http\Controllers\Admin\OnlinePharmacyPurchases\VendorPaymentsController as OnlinePharmacyVendorPaymentsController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\ExportAdministratorsController;
use App\Http\Controllers\ExportCustomersController;
use App\Http\Controllers\ExportLaboratoryPurchasesController;
use App\Http\Controllers\ExportLaboratoryTestsController;
use App\Http\Controllers\ExportMedicalAttentionSubscriptionsController;
use App\Http\Controllers\ExportOnlinePharmacyPurchasesController;

// === IMPORTACIONES NUEVAS ===
use App\Http\Controllers\Admin\LaboratoryNotificationController;
use App\Http\Controllers\Admin\LaboratoryQuoteController; // ← Aun existe
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware([
    'auth',
    'admin',
    'password.confirm',
])->group(function () {
    Route::name('admin.')->group(function () {
        Route::get('admin', AdminController::class)->name('admin');
        Route::resource('administrators', AdministratorController::class)->except(['show']);
        Route::post('administrators/export', ExportAdministratorsController::class)->name('administrators.export');
        Route::resource('customers', CustomerController::class)->only(['index', 'show', 'destroy']);
        Route::post('customers/export', ExportCustomersController::class)->name('customers.export');
        Route::resource('roles', RoleController::class)->except('show');
        Route::resource('laboratory-tests', LaboratoryTestController::class)->except(['destroy']);
        Route::post('laboratory-tests/export', ExportLaboratoryTestsController::class)->name('laboratory-tests.export');
        Route::resource('laboratory-appointments', LaboratoryAppointmentController::class)->except(['create', 'store', 'edit']);
        Route::resource('laboratory-vendor-payments', LaboratoryVendorPaymentsController::class)->parameters([
            'laboratory-vendor-payments' => 'vendor_payment',
        ])->names([
            'index' => 'laboratory-purchases.vendor-payments.index',
            'create' => 'laboratory-purchases.vendor-payments.create',
            'store' => 'laboratory-purchases.vendor-payments.store',
            'show' => 'laboratory-purchases.vendor-payments.show',
            'edit' => 'laboratory-purchases.vendor-payments.edit',
            'update' => 'laboratory-purchases.vendor-payments.update',
            'destroy' => 'laboratory-purchases.vendor-payments.destroy',
        ]);
        Route::resource('laboratory-purchases', LaboratoryPurchaseController::class)->only(['index', 'show', 'destroy']);
        Route::post('laboratory-purchases/{laboratory_purchase}/invoice', InvoiceController::class)->name('laboratory-purchases.invoice');
        Route::post('laboratory-purchases/{laboratory_purchase}/results', ResultsController::class)->name('laboratory-purchases.results');
        Route::post('laboratory-purchases/{laboratory_purchase}/dev-assistance-request', LaboratoryDevAssistanceRequestController::class)->name('laboratory-purchases.dev-assistance-request.store');
        Route::post('laboratory-purchases/{laboratory_purchase}/dev-assistance-request/{dev_assistance_request}/resolved', LaboratoryResolvedDevAssistanceRequestController::class)->name('laboratory-purchases.dev-assistance-request.resolved');
        Route::post('laboratory-purchases/{laboratory_purchase}/dev-assistance-request/{dev_assistance_request}/unresolved', LaboratoryUnresolvedDevAssistanceRequestController::class)->name('laboratory-purchases.dev-assistance-request.unresolved');
        Route::post('laboratory-purchases/export', ExportLaboratoryPurchasesController::class)->name('laboratory-purchases.export');
        
        // ===== RUTAS NUEVAS PARA NOTIFICACIONES DE LABORATORIO =====
        Route::resource('laboratory-notifications', LaboratoryNotificationController::class)->only(['index', 'show']);
        Route::post('laboratory-notifications/{notification}/resend', [LaboratoryNotificationController::class, 'resend'])
            ->name('laboratory-notifications.resend');
        Route::get('laboratory-notifications/{notification}/details', [LaboratoryNotificationController::class, 'showDetails'])
            ->name('laboratory-notifications.details');
        Route::delete('laboratory-notifications/{notification}/clean', [LaboratoryNotificationController::class, 'cleanError'])
            ->name('laboratory-notifications.clean-error');
        // ===========================================================
        
        Route::resource('online-pharmacy-vendor-payments', OnlinePharmacyVendorPaymentsController::class)->parameters([
            'online-pharmacy-vendor-payments' => 'vendor_payment',
        ])->names([
            'index' => 'online-pharmacy-purchases.vendor-payments.index',
            'create' => 'online-pharmacy-purchases.vendor-payments.create',
            'store' => 'online-pharmacy-purchases.vendor-payments.store',
            'show' => 'online-pharmacy-purchases.vendor-payments.show',
            'edit' => 'online-pharmacy-purchases.vendor-payments.edit',
            'update' => 'online-pharmacy-purchases.vendor-payments.update',
            'destroy' => 'online-pharmacy-purchases.vendor-payments.destroy',
        ]);
        Route::resource('online-pharmacy-purchases', OnlinePharmacyPurchaseController::class)->only(['index', 'show']);
        Route::post('online-pharmacy-purchases/{online_pharmacy_purchase}/invoice', OnlinePharmacyPurchasesInvoiceController::class)->name('online-pharmacy-purchases.invoice');
        Route::post('online-pharmacy-purchases/{online_pharmacy_purchase}/dev-assistance-request', OnlinePharmacyDevAssistanceRequestController::class)->name('online-pharmacy-purchases.dev-assistance-request.store');
        Route::post('online-pharmacy-purchases/{online_pharmacy_purchase}/dev-assistance-request/{dev_assistance_request}/resolved', OnlinePharmacyResolvedDevAssistanceRequestController::class)->name('online-pharmacy-purchases.dev-assistance-request.resolved');
        Route::post('online-pharmacy-purchases/{online_pharmacy_purchase}/dev-assistance-request/{dev_assistance_request}/unresolved', OnlinePharmacyUnresolvedDevAssistanceRequestController::class)->name('online-pharmacy-purchases.dev-assistance-request.unresolved');
        Route::post('online-pharmacy-purchases/export', ExportOnlinePharmacyPurchasesController::class)->name('online-pharmacy-purchases.export');
        Route::resource('medical-attention-subscriptions', MedicalAttentionSubscriptionController::class)->only(['index', 'show']);
        Route::post('medical-attention-subscriptions/export', ExportMedicalAttentionSubscriptionsController::class)->name('medical-attention-subscriptions.export');
        Route::get('documentation', [DocumentationController::class, 'index'])->name('documentation');
        Route::patch('documentation', [DocumentationController::class, 'update'])->name('documentation.update');

        // ===== RUTAS PARA COTIZACIONES DE LABORATORIO (SI LAS NECESITAS) =====
        // Si tienes un controller para quotes de laboratorio, agrégala aquí
        // Route::resource('laboratory-quotes', LaboratoryQuoteController::class)->only(['index', 'show']);
        
    });
});