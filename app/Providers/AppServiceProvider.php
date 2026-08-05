<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\OnlinePharmacyPurchase;
use App\Listeners\ApplyMailSafetyPolicy;
use App\Listeners\LinkPendingCouponBeneficiaries;
use App\Services\ConstanciaFiscalService;
use App\Services\Tracking\Tracking;
use App\Services\ClinicalMatching\Catalog\CatalogAdapterInterface;
use App\Services\ClinicalMatching\Catalog\CompositeCatalogAdapter;
use App\Services\ClinicalMatching\Catalog\LaboratoryCatalogAdapter;
use App\Services\ClinicalMatching\Catalog\NullMedicationCatalogAdapter;
use App\Services\DocumentInterpretation\DocumentInterpreterInterface;
use App\Services\DocumentInterpretation\OpenAIVisionInterpreter;
use App\Services\DocumentInterpretation\Prompts\FilePromptRepository;
use App\Services\DocumentInterpretation\Prompts\PromptRepositoryInterface;
use App\Services\ClinicalLearning\LearningSuggestionRecorder;
use App\Services\ClinicalLearning\LearningSuggestionRecorderInterface;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Stripe\StripeClient;
//use App\Services\EfevooPayService;
/*
use App\Services\EfevooPayFactoryService;
use App\Services\EfevooPaySimulatorService;
use App\Actions\Efevoo\ChargeEfevooTokenAction;
use App\Actions\Efevoo\RefundEfevooTransactionAction;
use App\Actions\EfevooPay\ChargeEfevooPaymentMethodAction;
*/
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //$this->app->singleton(ChargeEfevooTokenAction::class);
        //$this->app->singleton(RefundEfevooTransactionAction::class);

        $this->app->singleton(StripeClient::class, function ($app) {
            return new StripeClient(config('services.stripe.secret'));
        });

        $this->app->singleton(Tracking::class, function ($app) {
            return new Tracking;
        });

        if ($this->app->environment('local', 'testing')) {
            $this->app->register(DuskServiceProvider::class);
        }

        $this->app->singleton(ConstanciaFiscalService::class, function ($app) {
            return new ConstanciaFiscalService();
        });

        $this->app->singleton(CatalogAdapterInterface::class, function ($app) {
            return new CompositeCatalogAdapter([
                $app->make(LaboratoryCatalogAdapter::class),
                $app->make(NullMedicationCatalogAdapter::class),
            ]);
        });

        $this->app->singleton(PromptRepositoryInterface::class, FilePromptRepository::class);
        $this->app->bind(DocumentInterpreterInterface::class, OpenAIVisionInterpreter::class);
        $this->app->bind(LearningSuggestionRecorderInterface::class, LearningSuggestionRecorder::class);

        $this->app->register(\App\Providers\EfevooPayServiceProvider::class);
        $this->app->register(\App\Providers\ActiveCampaignServiceProvider::class);

        /*
        // Servicios de EfevooPay
        $this->app->singleton(EfevooPayFactoryService::class, function ($app) {
            return new EfevooPayFactoryService(
                $app->make(EfevooPayService::class),
                $app->make(EfevooPaySimulatorService::class)
            );
        });*/
        
        //$this->app->singleton(EfevooPayService::class);
        //$this->app->singleton(EfevooPaySimulatorService::class);
        
        /*
        // Acciones de EfevooPay
        $this->app->singleton(ChargeEfevooTokenAction::class);
        $this->app->singleton(RefundEfevooTransactionAction::class);
        $this->app->singleton(ChargeEfevooPaymentMethodAction::class);
        */
        
        // También mantener el servicio original disponible
        //$this->app->singleton(EfevooPayFactoryService::class);
    }

    public function boot(): void
    {
        RateLimiter::for('tax-profile-extract', function (Request $request) {
            return Limit::perMinute(5)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('clinical-interpreter-interpret', function (Request $request) {
            return Limit::perMinute(8)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        Event::listen(Verified::class, LinkPendingCouponBeneficiaries::class);
        Event::listen(MessageSending::class, ApplyMailSafetyPolicy::class);

        Gate::define('assign-autorizador-role', function ($user): bool {
            return (bool) $user->administrator?->hasRole('superadmin');
        });

        Gate::define('access-laboratory-billing', function ($user): bool {
            return app(\App\Services\LaboratoryBilling\LaboratoryBillingAccess::class)->allows($user);
        });

        Route::bind('laboratory_purchase', function ($value) {
            return LaboratoryPurchase::withTrashed()->findOrFail($value);
        });

        Route::bind('online_pharmacy_purchase', function ($value) {
            return OnlinePharmacyPurchase::withTrashed()->findOrFail($value);
        });

        RedirectResponse::macro('flashMessage', function ($message, $type = 'success') {
            return $this->with('flashMessage', [
                'type' => $type,
                'message' => $message,
            ]);
        });

        Redirector::macro('flashMessage', function ($message, $type = 'success') {
            return redirect()->with('flashMessage', [
                'type' => $type,
                'message' => $message,
            ]);
        });       

        Cashier::useCustomerModel(Customer::class);       
        
        Inertia::share([
            'flash' => function () {
                return [
                    'success' => session('success'),
                    'error' => session('error'),
                    'warning' => session('warning'),
                    'info' => session('info'),
                ];
            },
        ]);
    }
}
