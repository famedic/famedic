<?php

use App\Http\Controllers\Admin\ActiveCampaignController;
use App\Http\Controllers\Admin\ActiveCampaignOperationsController;
use App\Http\Controllers\Admin\AdministratorController;
use App\Http\Controllers\Admin\ArchiveMarketingCampaignController;
use App\Http\Controllers\Admin\AutomationOperationsController;
use App\Http\Controllers\Admin\CartController;
use App\Http\Controllers\Admin\ClinicalInterpreterController;
use App\Http\Controllers\Admin\ConfigMonitorController;
use App\Http\Controllers\Admin\ConfigMonitorMetadataController;
use App\Http\Controllers\Admin\CouponAuthorizationController;
use App\Http\Controllers\Admin\CouponBeneficiaryController;
use App\Http\Controllers\Admin\CouponConceptController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\CouponCreationOtpController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\CustomerReferralController;
use App\Http\Controllers\Admin\DocumentationController;
use App\Http\Controllers\Admin\EfevooTokenController;
use App\Http\Controllers\Admin\EmailSimulatorController;
use App\Http\Controllers\Admin\GdaNotificationSimulatorController;
use App\Http\Controllers\Admin\LaboratoryAppointmentController;
use App\Http\Controllers\Admin\LaboratoryAppointmentMetricsController;
use App\Http\Controllers\Admin\LaboratoryBilling\DashboardController;
use App\Http\Controllers\Admin\LaboratoryBilling\ExportController as LaboratoryBillingExportController;
use App\Http\Controllers\Admin\LaboratoryBilling\InvoicesController;
use App\Http\Controllers\Admin\LaboratoryBilling\ReportsController;
use App\Http\Controllers\Admin\LaboratoryBilling\RequestsController;
use App\Http\Controllers\Admin\LaboratoryBilling\TaxProfilesController as TaxProfilesBillingController;
use App\Http\Controllers\Admin\LaboratoryNotificationController;
use App\Http\Controllers\Admin\LaboratoryNotificationMonitorController;
use App\Http\Controllers\Admin\LaboratoryPurchaseChartController;
use App\Http\Controllers\Admin\LaboratoryPurchaseController;
use App\Http\Controllers\Admin\LaboratoryPurchases\DevAssistanceRequestController as LaboratoryDevAssistanceRequestController;
use App\Http\Controllers\Admin\LaboratoryPurchases\InvoiceController;
use App\Http\Controllers\Admin\LaboratoryPurchases\ResolvedDevAssistanceRequestController as LaboratoryResolvedDevAssistanceRequestController;
use App\Http\Controllers\Admin\LaboratoryPurchases\ResultsController;
use App\Http\Controllers\Admin\LaboratoryPurchases\UnresolvedDevAssistanceRequestController as LaboratoryUnresolvedDevAssistanceRequestController;
use App\Http\Controllers\Admin\LaboratoryPurchases\VendorPaymentsController as LaboratoryVendorPaymentsController;
use App\Http\Controllers\Admin\LaboratoryResultController;
use App\Http\Controllers\Admin\LaboratoryTestController;
use App\Http\Controllers\Admin\LogsGeneralController;
use App\Http\Controllers\Admin\MarketingCampaignCollectionController;
use App\Http\Controllers\Admin\MarketingCampaignController;
use App\Http\Controllers\Admin\MarketingCampaignLinkController;
use App\Http\Controllers\Admin\MarketingCampaignProductSearchController;
use App\Http\Controllers\Admin\MedicalAttentionSubscriptionController;
use App\Http\Controllers\Admin\MonitoringAiController;
use App\Http\Controllers\Admin\MurguiaDashboardController;
use App\Http\Controllers\Admin\MurguiaMonitorController;
use App\Http\Controllers\Admin\MurguiaReconciliationController;
use App\Http\Controllers\Admin\MurguiaReportController;
use App\Http\Controllers\Admin\OnlinePharmacyPurchaseController;
use App\Http\Controllers\Admin\OnlinePharmacyPurchases\DevAssistanceRequestController as OnlinePharmacyDevAssistanceRequestController;
use App\Http\Controllers\Admin\OnlinePharmacyPurchases\InvoiceController as OnlinePharmacyPurchasesInvoiceController;
use App\Http\Controllers\Admin\OnlinePharmacyPurchases\ResolvedDevAssistanceRequestController as OnlinePharmacyResolvedDevAssistanceRequestController;
use App\Http\Controllers\Admin\OnlinePharmacyPurchases\UnresolvedDevAssistanceRequestController as OnlinePharmacyUnresolvedDevAssistanceRequestController;
use App\Http\Controllers\Admin\OnlinePharmacyPurchases\VendorPaymentsController as OnlinePharmacyVendorPaymentsController;
use App\Http\Controllers\Admin\OtpSimulatorController;
use App\Http\Controllers\Admin\PaymentAttemptController as AdminPaymentAttemptController;
use App\Http\Controllers\Admin\PromoCodeController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SimulatorController;
use App\Http\Controllers\Admin\TaxProfileController as AdminTaxProfileController;
use App\Http\Controllers\Admin\TaxProfileFiscalCertificateController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\ExportAdministratorsController;
use App\Http\Controllers\ExportCartsController;
use App\Http\Controllers\ExportCustomersController;
use App\Http\Controllers\ExportLaboratoryPurchasesController;
use App\Http\Controllers\ExportLaboratoryTestsController;
// === IMPORTACIONES NUEVAS ===
use App\Http\Controllers\ExportMedicalAttentionSubscriptionsController;
// use App\Http\Controllers\Admin\LaboratoryQuoteController; // ← Aun existe
use App\Http\Controllers\ExportOnlinePharmacyPurchasesController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware([
    'auth',
    'admin',
    'password.confirm',
])->group(function () {
    Route::name('admin.')->group(function () {
        Route::get('admin', AdminController::class)->name('admin');

        Route::prefix('workspace')->name('workspace.')->group(function () {
            Route::get('/', \App\Http\Controllers\Admin\Workspace\WorkspaceHomeController::class)
                ->name('index');
            // Producto independiente ActiveCampaign Hub
            Route::get('activecampaign', \App\Http\Controllers\Admin\Workspace\ActiveCampaignHubController::class)
                ->name('activecampaign');
            // Compatibilidad: ruta anterior bajo Customer Engagement
            Route::redirect('customer-engagement/activecampaign', '/admin/workspace/activecampaign')
                ->name('customer-engagement.activecampaign');
            Route::get('{workspace}', \App\Http\Controllers\Admin\Workspace\WorkspaceShowController::class)
                ->where('workspace', 'clinical-ai|customers|marketing|customer-engagement|executive|ai|platform')
                ->name('show');
        });

        // Compatibilidad: Intelligence Hub → Workspace
        Route::redirect('intelligence', '/admin/workspace')->name('intelligence.index');
        Route::get('intelligence/{suite}', function (string $suite) {
            $map = [
                'customer' => 'customers',
                'marketing' => 'customer-engagement',
                'executive' => 'executive',
                'business' => 'executive',
                'ai-operations' => 'ai',
                'operations' => 'platform',
                'governance' => 'platform',
            ];

            return redirect()->route('admin.workspace.show', $map[$suite] ?? 'customers');
        })->where('suite', 'customer|marketing|executive|business|ai-operations|operations|governance')
            ->name('intelligence.suite');

        Route::resource('administrators', AdministratorController::class)->except(['show']);
        Route::post('administrators/export', ExportAdministratorsController::class)->name('administrators.export');
        Route::get('customers/referrals', [CustomerReferralController::class, 'index'])->name('customers.referrals');
        Route::get('customers/dormant', [\App\Http\Controllers\Admin\CustomerIntelligence\DormantCustomersController::class, 'index'])
            ->name('customers.dormant');
        Route::prefix('customer-intelligence')->name('customer-intelligence.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\CustomerIntelligence\CustomerIntelligenceHubController::class, '__invoke'])
                ->name('index');
            Route::get('customer-journey', [\App\Http\Controllers\Admin\CustomerIntelligence\CustomerJourneyController::class, 'index'])
                ->name('customer-journey');
            Route::get('customer-journey/data', [\App\Http\Controllers\Admin\CustomerIntelligence\CustomerJourneyController::class, 'data'])
                ->name('customer-journey.data');
            Route::get('cohorts', [\App\Http\Controllers\Admin\CustomerIntelligence\CohortsController::class, 'index'])
                ->name('cohorts');
            Route::get('cohorts/data', [\App\Http\Controllers\Admin\CustomerIntelligence\CohortsController::class, 'data'])
                ->name('cohorts.data');
            Route::get('customer-health', [\App\Http\Controllers\Admin\CustomerIntelligence\CustomerHealthController::class, 'index'])
                ->name('customer-health');
            Route::get('customer-health/data', [\App\Http\Controllers\Admin\CustomerIntelligence\CustomerHealthController::class, 'data'])
                ->name('customer-health.data');
        });
        Route::resource('customers', CustomerController::class)->only(['index', 'show', 'destroy']);
        Route::post('customers/export', ExportCustomersController::class)->name('customers.export');
        Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('users/{user}/verify-email', [UserController::class, 'verifyEmail'])->name('users.verify-email');
        Route::post('users/{user}/verify-phone', [UserController::class, 'verifyPhone'])->name('users.verify-phone');
        Route::post('users/{user}/update-password', [UserController::class, 'updatePassword'])->name('users.update-password');
        Route::resource('users', UserController::class)->only(['index', 'show']);
        Route::get('carts/dashboard', [\App\Http\Controllers\Admin\CartsDashboardController::class, 'index'])->name('carts.dashboard');
        Route::resource('carts', CartController::class)->only(['index', 'show']);
        Route::post('carts/export', ExportCartsController::class)->name('carts.export');
        Route::resource('roles', RoleController::class)->except('show');
        Route::resource('laboratory-tests', LaboratoryTestController::class)->except(['destroy']);
        Route::post('laboratory-tests/export', ExportLaboratoryTestsController::class)->name('laboratory-tests.export');

        Route::prefix('marketing-campaigns')->name('marketing-campaigns.')->group(function () {
            Route::get('product-search', MarketingCampaignProductSearchController::class)->name('product-search');

            Route::get('/', [MarketingCampaignController::class, 'index'])->name('index');
            Route::get('/create', [MarketingCampaignController::class, 'create'])->name('create');
            Route::post('/', [MarketingCampaignController::class, 'store'])->name('store');
            Route::get('/{marketing_campaign}', [MarketingCampaignController::class, 'show'])->name('show');
            Route::get('/{marketing_campaign}/edit', [MarketingCampaignController::class, 'edit'])->name('edit');
            Route::put('/{marketing_campaign}', [MarketingCampaignController::class, 'update'])->name('update');
            Route::post('/{marketing_campaign}/archive', ArchiveMarketingCampaignController::class)->name('archive');

            Route::get('/{marketing_campaign}/links/create', [MarketingCampaignLinkController::class, 'create'])->name('links.create');
            Route::post('/{marketing_campaign}/links', [MarketingCampaignLinkController::class, 'store'])->name('links.store');
            Route::get('/{marketing_campaign}/links/{marketing_campaign_link}/edit', [MarketingCampaignLinkController::class, 'edit'])->name('links.edit');
            Route::put('/{marketing_campaign}/links/{marketing_campaign_link}', [MarketingCampaignLinkController::class, 'update'])->name('links.update');

            Route::get('/{marketing_campaign}/collections/create', [MarketingCampaignCollectionController::class, 'create'])->name('collections.create');
            Route::post('/{marketing_campaign}/collections', [MarketingCampaignCollectionController::class, 'store'])->name('collections.store');
            Route::get('/{marketing_campaign}/collections/{marketing_campaign_collection}/edit', [MarketingCampaignCollectionController::class, 'edit'])->name('collections.edit');
            Route::put('/{marketing_campaign}/collections/{marketing_campaign_collection}', [MarketingCampaignCollectionController::class, 'update'])->name('collections.update');
        });
        Route::get('laboratory-appointments/metrics', LaboratoryAppointmentMetricsController::class)->name('laboratory-appointments.metrics');
        Route::post('laboratory-appointments/{laboratory_appointment}/interactions', [LaboratoryAppointmentController::class, 'storeInteraction'])
            ->name('laboratory-appointments.interactions.store');
        Route::post('laboratory-appointments/{laboratory_appointment}/send-payment-summary', [LaboratoryAppointmentController::class, 'sendPaymentSummary'])
            ->name('laboratory-appointments.send-payment-summary');
        Route::post('laboratory-appointments/{laboratory_appointment}/send-appointment-instructions', [LaboratoryAppointmentController::class, 'sendAppointmentInstructions'])
            ->name('laboratory-appointments.send-appointment-instructions');
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
        Route::get('laboratory-purchases/chart', [LaboratoryPurchaseChartController::class, 'index'])
            ->name('laboratory-purchases.chart');
        Route::resource('laboratory-purchases', LaboratoryPurchaseController::class)->only(['index', 'show', 'destroy']);
        Route::post(
            'laboratory-purchases/{laboratory_purchase}/resend-confirmation-email',
            [LaboratoryPurchaseController::class, 'resendConfirmationEmail']
        )->name('laboratory-purchases.resend-confirmation-email');
        Route::post('laboratory-purchases/{laboratory_purchase}/invoice', InvoiceController::class)->name('laboratory-purchases.invoice');
        Route::post('laboratory-purchases/{laboratory_purchase}/results', ResultsController::class)->name('laboratory-purchases.results');
        Route::post('laboratory-purchases/{laboratory_purchase}/dev-assistance-request', LaboratoryDevAssistanceRequestController::class)->name('laboratory-purchases.dev-assistance-request.store');
        Route::post('laboratory-purchases/{laboratory_purchase}/dev-assistance-request/{dev_assistance_request}/resolved', LaboratoryResolvedDevAssistanceRequestController::class)->name('laboratory-purchases.dev-assistance-request.resolved');
        Route::post('laboratory-purchases/{laboratory_purchase}/dev-assistance-request/{dev_assistance_request}/unresolved', LaboratoryUnresolvedDevAssistanceRequestController::class)->name('laboratory-purchases.dev-assistance-request.unresolved');
        Route::post('laboratory-purchases/export', ExportLaboratoryPurchasesController::class)->name('laboratory-purchases.export');

        Route::prefix('laboratory-billing')->name('laboratory-billing.')->group(function () {
            Route::get('/', DashboardController::class)->name('dashboard');
            Route::get('/requests', RequestsController::class)->name('requests');
            Route::get('/invoices', InvoicesController::class)->name('invoices');
            Route::get('/tax-profiles', [TaxProfilesBillingController::class, 'index'])->name('tax-profiles.index');
            Route::get('/tax-profiles/{tax_profile}', [TaxProfilesBillingController::class, 'show'])->name('tax-profiles.show');
            Route::get('/reports', ReportsController::class)->name('reports');
            Route::get('/export/requests', [LaboratoryBillingExportController::class, 'requests'])->name('export.requests');
            Route::get('/export/invoices', [LaboratoryBillingExportController::class, 'invoices'])->name('export.invoices');
            Route::get('/export/tax-profiles', [LaboratoryBillingExportController::class, 'taxProfiles'])->name('export.tax-profiles');
        });

        // ===== RUTAS NUEVAS PARA NOTIFICACIONES DE LABORATORIO =====
        Route::resource('laboratory-notifications', LaboratoryNotificationController::class)->only(['index', 'show']);
        Route::post('laboratory-notifications/{notification}/resend', [LaboratoryNotificationController::class, 'resend'])
            ->name('laboratory-notifications.resend');
        Route::get('laboratory-notifications/{notification}/details', [LaboratoryNotificationController::class, 'showDetails'])
            ->name('laboratory-notifications.details');
        Route::delete('laboratory-notifications/{notification}/clean', [LaboratoryNotificationController::class, 'cleanError'])
            ->name('laboratory-notifications.clean-error');

        // ===== RUTAS PARA OBTENER RESULTADOS DE LABORATORIO =====
        Route::post('/laboratory-purchases/{laboratoryPurchase}/fetch-results', [LaboratoryResultController::class, 'fetch']
        )->name('laboratory-purchases.fetch-results');

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
        Route::get('logs-general/manage', [LogsGeneralController::class, 'index'])->name('logs-general.manage');
        Route::get('logs-general/download', [LogsGeneralController::class, 'download'])->name('logs-general.download');

        // Asistente IA de monitoreo
        Route::get('monitoring-ai', [MonitoringAiController::class, 'index'])->name('monitoring-ai.index');
        Route::post('monitoring-ai/ask', [MonitoringAiController::class, 'ask'])->name('monitoring-ai.ask');

        // AI Clinical Interpreter · producto navegable
        Route::prefix('clinical-interpreter')->name('clinical-interpreter.')->group(function () {
            Route::get('/', [ClinicalInterpreterController::class, 'index'])->name('index');
            Route::get('/history', [ClinicalInterpreterController::class, 'history'])->name('history');
            Route::get('/orders', [ClinicalInterpreterController::class, 'ordersIndex'])->name('orders.index');
            Route::get('/learning', [ClinicalInterpreterController::class, 'learning'])->name('learning');
            Route::get('/config', [ClinicalInterpreterController::class, 'config'])->name('config');
            Route::get('/operations', [ClinicalInterpreterController::class, 'operations'])->name('operations');

            Route::get('/assistant', [ClinicalInterpreterController::class, 'assistant'])->name('assistant');
            Route::get('/matching', [ClinicalInterpreterController::class, 'matching'])->name('matching');
            Route::post('/interpret', [ClinicalInterpreterController::class, 'interpret'])
                ->middleware('throttle:clinical-interpreter-interpret')
                ->name('interpret');
            Route::get('/catalog-search', [ClinicalInterpreterController::class, 'searchCatalog'])->name('catalog-search');
            Route::post('/learning-suggestions', [ClinicalInterpreterController::class, 'recordLearning'])->name('learning-suggestions.store');
            Route::post('/commercial/proposal', [ClinicalInterpreterController::class, 'commercialProposal'])->name('commercial.proposal');
            Route::post('/commercial/draft', [ClinicalInterpreterController::class, 'commercialDraft'])->name('commercial.draft');
            Route::post('/commercial/quote', [ClinicalInterpreterController::class, 'commercialQuote'])->name('commercial.quote');
            Route::post('/commercial/cart', [ClinicalInterpreterController::class, 'commercialCart'])->name('commercial.cart');
            Route::get('/customers/search', [ClinicalInterpreterController::class, 'searchCustomers'])->name('customers.search');
            Route::post('/clinical-orders', [ClinicalInterpreterController::class, 'storeClinicalOrder'])->name('clinical-orders.store');
            Route::get('/clinical-orders/{clinicalOrder}', [ClinicalInterpreterController::class, 'showClinicalOrder'])->name('clinical-orders.show');
            Route::post('/clinical-orders/{clinicalOrder}/quote', [ClinicalInterpreterController::class, 'clinicalOrderQuote'])->name('clinical-orders.quote');
            Route::post('/clinical-orders/{clinicalOrder}/cart', [ClinicalInterpreterController::class, 'clinicalOrderCart'])->name('clinical-orders.cart');
        });

        // Tokens de Efevoo
        Route::resource('efevoo-tokens', EfevooTokenController::class)->only(['index', 'show']);

        // Perfiles fiscales (agrupados por usuario/cliente)
        Route::get('tax-profiles', [AdminTaxProfileController::class, 'index'])->name('tax-profiles.index');
        Route::get('tax-profiles/{tax_profile}/fiscal-certificate', TaxProfileFiscalCertificateController::class)
            ->name('tax-profiles.fiscal-certificate');
        Route::get('tax-profiles/{customer}', [AdminTaxProfileController::class, 'show'])->name('tax-profiles.show');

        // Intentos de pago
        Route::resource('payment-attempts', AdminPaymentAttemptController::class)->only(['index', 'show']);

        // Monitoreo de notificaciones de laboratorio (toma de muestra vs resultados)
        Route::get('laboratory-notifications-monitor', [LaboratoryNotificationMonitorController::class, 'index'])
            ->name('laboratory-notifications-monitor.index');
        Route::get('laboratory-notifications-monitor/order/{orderKey}/details', [LaboratoryNotificationMonitorController::class, 'orderDetails'])
            ->name('laboratory-notifications-monitor.order-details');
        Route::post('laboratory-notifications-monitor/order/{orderKey}/fetch-results', [LaboratoryNotificationMonitorController::class, 'fetchResults'])
            ->name('laboratory-notifications-monitor.fetch-results');
        Route::post('laboratory-notifications-monitor/order/{orderKey}/force-refresh-results', [LaboratoryNotificationMonitorController::class, 'forceRefreshResults'])
            ->name('laboratory-notifications-monitor.force-refresh-results');
        Route::post('laboratory-notifications-monitor/order/{orderKey}/test-gda-consult', [LaboratoryNotificationMonitorController::class, 'testGdaConsult'])
            ->name('laboratory-notifications-monitor.test-gda-consult');
        Route::get('laboratory-notifications-monitor/order/{orderKey}/download-results', [LaboratoryNotificationMonitorController::class, 'downloadResults'])
            ->name('laboratory-notifications-monitor.download-results');
        Route::get('laboratory-notifications-monitor/{gdaOrderId}', [LaboratoryNotificationMonitorController::class, 'show'])
            ->name('laboratory-notifications-monitor.show');

        Route::get('coupons/beneficiaries/export', [CouponBeneficiaryController::class, 'export'])->name('coupons.beneficiaries.export');
        Route::get('coupons/beneficiaries', [CouponBeneficiaryController::class, 'index'])->name('coupons.beneficiaries.index');
        Route::get('coupons/promo-codes', [PromoCodeController::class, 'index'])->name('coupons.promo-codes.index');
        Route::get('coupons/promo-codes/create', [PromoCodeController::class, 'create'])->name('coupons.promo-codes.create');
        Route::post('coupons/promo-codes', [PromoCodeController::class, 'store'])->name('coupons.promo-codes.store');
        Route::post('coupons/promo-codes/check-code', [PromoCodeController::class, 'checkCode'])->name('coupons.promo-codes.check-code');
        Route::get('coupons/promo-codes/{promoCode}', [PromoCodeController::class, 'show'])->name('coupons.promo-codes.show');
        Route::post('coupons/promo-codes/{promoCode}/deactivate', [PromoCodeController::class, 'deactivate'])->name('coupons.promo-codes.deactivate');
        Route::get('coupons/export', [CouponController::class, 'export'])->name('coupons.export');
        Route::get('coupons/settings', [CouponController::class, 'settings'])->name('coupons.settings');
        Route::put('coupons/settings', [CouponController::class, 'updateSettings'])->name('coupons.settings.update');
        Route::get('coupons/logs', [CouponController::class, 'logs'])->name('coupons.logs');
        Route::get('coupons/assign', [CouponController::class, 'assignForm'])->name('coupons.assign');
        Route::get('coupons/assign/bulk-template', [CouponController::class, 'downloadBulkAssignTemplate'])->name('coupons.assign.bulk-template');
        Route::get('coupons/users/lookup', [CouponController::class, 'lookupAssignableUser'])->name('coupons.users.lookup');
        Route::post('coupons/assign/preview-bulk', [CouponController::class, 'previewBulkAssignEmails'])->name('coupons.assign.preview-bulk');
        Route::post('coupons/{coupon}/beneficiaries/preview', [CouponController::class, 'previewBeneficiaries'])->name('coupons.beneficiaries.preview');
        Route::post('coupons/{coupon}/beneficiaries/preview-file', [CouponController::class, 'previewBeneficiariesFile'])->name('coupons.beneficiaries.preview-file');
        Route::post('coupons/{coupon}/beneficiaries/confirm', [CouponController::class, 'confirmBeneficiaries'])->name('coupons.beneficiaries.confirm');
        Route::post('coupons/{coupon}/beneficiaries/{beneficiary}/resend-invitation', [CouponController::class, 'resendBeneficiaryInvitation'])->name('coupons.beneficiaries.resend-invitation');
        Route::post('coupons/{coupon}/beneficiaries/{beneficiary}/cancel', [CouponController::class, 'cancelBeneficiary'])->name('coupons.beneficiaries.cancel');
        Route::post('coupons/assign/creation-otp/send', [CouponCreationOtpController::class, 'send'])->name('coupons.assign.creation-otp.send');
        Route::post('coupons/assign/creation-otp/resend', [CouponCreationOtpController::class, 'resend'])->name('coupons.assign.creation-otp.resend');
        Route::post('coupons/assign/creation-otp/verify', [CouponCreationOtpController::class, 'verify'])->name('coupons.assign.creation-otp.verify');
        Route::get('coupons/authorizations', [CouponAuthorizationController::class, 'index'])->name('coupons.authorizations.index');
        Route::get('coupons/authorizations/{coupon}', [CouponAuthorizationController::class, 'show'])->name('coupons.authorizations.show');
        Route::post('coupons/authorizations/{coupon}/approval-otp/send', [CouponAuthorizationController::class, 'sendApprovalOtp'])->name('coupons.authorizations.approval-otp.send');
        Route::post('coupons/authorizations/{coupon}/approval-otp/verify', [CouponAuthorizationController::class, 'verifyApprovalOtp'])->name('coupons.authorizations.approval-otp.verify');
        Route::post('coupons/authorizations/{coupon}/approve', [CouponAuthorizationController::class, 'approve'])->name('coupons.authorizations.approve');
        Route::post('coupons/authorizations/{coupon}/reject', [CouponAuthorizationController::class, 'reject'])->name('coupons.authorizations.reject');
        Route::post('coupons/assign', [CouponController::class, 'assign'])->name('coupons.assign.store');
        Route::get('coupons/import', [CouponController::class, 'importForm'])->name('coupons.import');
        Route::post('coupons/import', [CouponController::class, 'import'])->name('coupons.import.store');
        Route::post('coupons/approval-requests/{approvalRequest}/approve', [CouponController::class, 'approveRequest'])->name('coupons.approval-requests.approve');
        Route::post('coupons/approval-requests/{approvalRequest}/reject', [CouponController::class, 'rejectRequest'])->name('coupons.approval-requests.reject');
        Route::post('coupons/concepts', [CouponConceptController::class, 'store'])->name('coupons.concepts.store');
        Route::put('coupons/concepts/{couponConcept}', [CouponConceptController::class, 'update'])->name('coupons.concepts.update');
        Route::delete('coupons/concepts/{couponConcept}', [CouponConceptController::class, 'destroy'])->name('coupons.concepts.destroy');
        Route::post('coupons/{coupon}/authorize', [CouponController::class, 'authorizeCoupon'])->name('coupons.authorize');
        Route::post('coupons/{coupon}/resend-authorization', [CouponController::class, 'resendAuthorization'])->name('coupons.resend-authorization');
        Route::post('coupons/{coupon}/deactivate', [CouponController::class, 'deactivate'])->name('coupons.deactivate');
        Route::delete('coupons/{coupon}/assignments/{couponUser}', [CouponController::class, 'destroyAssignment'])->name('coupons.assignments.destroy');
        Route::resource('coupons', CouponController::class);
        Route::prefix('activecampaign')->name('activecampaign.')->group(function () {
            Route::get('/', [ActiveCampaignController::class, 'dashboard'])->name('dashboard');
            Route::get('/analytics', [ActiveCampaignController::class, 'analytics'])->name('analytics');
            Route::get('/contacts', [ActiveCampaignController::class, 'contacts'])->name('contacts');
            Route::get('/contacts/360', [ActiveCampaignController::class, 'patient360'])->name('patient-360');
            Route::get('/customer-journey', [ActiveCampaignController::class, 'customerJourney'])->name('customer-journey');
            Route::get('/automations', [ActiveCampaignController::class, 'automations'])->name('automations');
            Route::get('/automations/list', [ActiveCampaignController::class, 'automationsList'])->name('automations.list');
            Route::get('/automations/builder', [ActiveCampaignController::class, 'automationsBuilder'])->name('automations.builder');
            Route::get('/automations/{automation}', [ActiveCampaignController::class, 'automationsShow'])->name('automations.show');
            Route::get('/funnels', [ActiveCampaignController::class, 'funnels'])->name('funnels');
            Route::get('/events', [ActiveCampaignController::class, 'events'])->name('events');
            Route::get('/tags', [ActiveCampaignController::class, 'tags'])->name('tags');
            Route::get('/fields', [ActiveCampaignController::class, 'fields'])->name('fields');
            Route::get('/ecommerce', [ActiveCampaignController::class, 'ecommerce'])->name('ecommerce');
            Route::get('/laboratories', [ActiveCampaignController::class, 'laboratories'])->name('laboratories');
            Route::get('/memberships', [ActiveCampaignController::class, 'memberships'])->name('memberships');
            Route::get('/alerts', [ActiveCampaignController::class, 'alerts'])->name('alerts');
            Route::get('/notifications', [ActiveCampaignController::class, 'notifications'])->name('notifications');
            Route::get('/logs', [ActiveCampaignController::class, 'logs'])->name('logs');
            Route::get('/health', [ActiveCampaignController::class, 'health'])->name('health');
            Route::get('/integrations', [ActiveCampaignController::class, 'integrations'])->name('integrations');
            Route::get('/qa-compare', [ActiveCampaignController::class, 'qaCompare'])->name('qa-compare');
            Route::get('/settings', [ActiveCampaignController::class, 'settings'])->name('settings');
        });

        Route::prefix('integrations')->name('integrations.')->group(function () {
            Route::get('activecampaign', [ActiveCampaignOperationsController::class, 'index'])
                ->name('activecampaign');
            Route::post('activecampaign/test-api', [ActiveCampaignOperationsController::class, 'testApi'])
                ->name('activecampaign.test-api');
            Route::post('activecampaign/diagnostic', [ActiveCampaignOperationsController::class, 'diagnostic'])
                ->name('activecampaign.diagnostic');
            Route::get('activecampaign/export', [ActiveCampaignOperationsController::class, 'export'])
                ->name('activecampaign.export');
        });

        // Automation Operations Center — monitor / audit / diagnose only
        Route::get('automation', [AutomationOperationsController::class, 'index'])
            ->name('automation');
        Route::post('automation/diagnostic', [AutomationOperationsController::class, 'diagnostic'])
            ->name('automation.diagnostic');
        Route::post('automation/queue/action', [AutomationOperationsController::class, 'queueAction'])
            ->name('automation.queue.action');

        Route::get('simulators', [SimulatorController::class, 'index'])->name('simulators.index');
        Route::get('simulators/gda', [GdaNotificationSimulatorController::class, 'show'])->name('simulators.gda');
        Route::get('simulators/gda/{laboratory_purchase}/history', [GdaNotificationSimulatorController::class, 'history'])
            ->name('simulators.gda.history');
        Route::post('simulators/gda/{laboratory_purchase}/simulate', [GdaNotificationSimulatorController::class, 'simulate'])
            ->name('simulators.gda.simulate');
        Route::post('simulators/gda/{laboratory_purchase}/resend', [GdaNotificationSimulatorController::class, 'resend'])
            ->name('simulators.gda.resend');
        Route::get('simulators/emails', [EmailSimulatorController::class, 'index'])->name('simulators.emails');
        Route::get('simulators/emails/preview/{type}', [EmailSimulatorController::class, 'preview'])
            ->where('type', '[a-z0-9_]+')
            ->name('simulators.emails.preview');
        Route::get('simulators/otp', [OtpSimulatorController::class, 'show'])->name('simulators.otp');
        Route::prefix('simulators/otp/{laboratory_purchase}')->name('simulators.otp.')->group(function () {
            Route::get('status', [OtpSimulatorController::class, 'status'])->name('status');
            Route::post('send', [OtpSimulatorController::class, 'send'])->name('send');
            Route::post('resend', [OtpSimulatorController::class, 'resend'])->name('resend');
            Route::post('verify', [OtpSimulatorController::class, 'verify'])->name('verify');
        });

        // Monitor de configuración (solo lectura; metadatos en BD)
        Route::get('config-monitor', [ConfigMonitorController::class, 'index'])->name('config-monitor.index');
        Route::post('config-monitor/refresh', [ConfigMonitorController::class, 'refresh'])->name('config-monitor.refresh');
        Route::prefix('config-monitor/metadata')->name('config-monitor.metadata.')->group(function () {
            Route::get('/', [ConfigMonitorMetadataController::class, 'index'])->name('index');
            Route::post('/groups', [ConfigMonitorMetadataController::class, 'storeGroup'])->name('groups.store');
            Route::patch('/groups/{group}', [ConfigMonitorMetadataController::class, 'updateGroup'])->name('groups.update');
            Route::delete('/groups/{group}', [ConfigMonitorMetadataController::class, 'destroyGroup'])->name('groups.destroy');
            Route::post('/settings', [ConfigMonitorMetadataController::class, 'storeSetting'])->name('settings.store');
            Route::patch('/settings/{setting}', [ConfigMonitorMetadataController::class, 'updateSetting'])->name('settings.update');
            Route::delete('/settings/{setting}', [ConfigMonitorMetadataController::class, 'destroySetting'])->name('settings.destroy');
        });

        Route::middleware('super.admin')->group(function () {
            Route::get('murguia-dashboard', [MurguiaDashboardController::class, 'index'])->name('murguia-dashboard.index');
            Route::get('murguia-reports', [MurguiaReportController::class, 'index'])->name('murguia-reports.index');
            Route::get('murguia-reports/export', [MurguiaReportController::class, 'export'])->name('murguia-reports.export');
            Route::get('murguia-reconciliation', [MurguiaReconciliationController::class, 'index'])->name('murguia-reconciliation.index');
            Route::post('murguia-reconciliation/upload', [MurguiaReconciliationController::class, 'upload'])->name('murguia-reconciliation.upload');
            Route::delete('murguia-reconciliation/preview', [MurguiaReconciliationController::class, 'clear'])->name('murguia-reconciliation.clear');
            Route::get('murguia-monitor', [MurguiaMonitorController::class, 'index'])->name('murguia-monitor.index');
            Route::get('murguia-monitor/{customer}', [MurguiaMonitorController::class, 'show'])->name('murguia-monitor.show');
            Route::post('murguia-monitor/{customer}/check-status', [MurguiaMonitorController::class, 'checkStatus'])->name('murguia-monitor.check-status');
            Route::post('murguia-monitor/check-status-by-credit', [MurguiaMonitorController::class, 'checkStatusByCredit'])->name('murguia-monitor.check-status-by-credit');
            Route::post('murguia/activate/{customer}', [MurguiaMonitorController::class, 'activateCustomer'])->name('murguia.activate');
            Route::post('murguia/deactivate/{customer}', [MurguiaMonitorController::class, 'deactivateCustomer'])->name('murguia.deactivate');
            Route::get('murguia/upload', [MurguiaMonitorController::class, 'uploadPage'])->name('murguia.upload');
            Route::post('murguia/upload-excel', [MurguiaMonitorController::class, 'uploadExcel'])->name('murguia.upload-excel');
            Route::get('murguia/logs', [MurguiaMonitorController::class, 'logs'])->name('murguia.logs');
        });

    });
});
