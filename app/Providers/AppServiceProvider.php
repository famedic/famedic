<?php

namespace App\Providers;

use App\Listeners\ApplyMailSafetyPolicy;
use App\Listeners\LinkPendingCouponBeneficiaries;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\OnlinePharmacyPurchase;
use App\Services\ClinicalLearning\LearningSuggestionRecorder;
use App\Services\ClinicalLearning\LearningSuggestionRecorderInterface;
use App\Services\ClinicalMatching\Catalog\CatalogAdapterInterface;
use App\Services\ClinicalMatching\Catalog\CompositeCatalogAdapter;
use App\Services\ClinicalMatching\Catalog\LaboratoryCatalogAdapter;
use App\Services\ClinicalMatching\Catalog\NullMedicationCatalogAdapter;
use App\Services\ConstanciaFiscalService;
use App\Services\DocumentInterpretation\DocumentInterpreterInterface;
use App\Services\DocumentInterpretation\OpenAIVisionInterpreter;
use App\Services\DocumentInterpretation\Prompts\FilePromptRepository;
use App\Services\DocumentInterpretation\Prompts\PromptRepositoryInterface;
use App\Services\Tracking\Tracking;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Cashier\Cashier;
// use App\Services\EfevooPayService;
/*
use App\Services\EfevooPayFactoryService;
use App\Services\EfevooPaySimulatorService;
use App\Actions\Efevoo\ChargeEfevooTokenAction;
use App\Actions\Efevoo\RefundEfevooTransactionAction;
use App\Actions\EfevooPay\ChargeEfevooPaymentMethodAction;
*/
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // $this->app->singleton(ChargeEfevooTokenAction::class);
        // $this->app->singleton(RefundEfevooTransactionAction::class);

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
            return new ConstanciaFiscalService;
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

        // $this->app->singleton(EfevooPayService::class);
        // $this->app->singleton(EfevooPaySimulatorService::class);

        /*
        // Acciones de EfevooPay
        $this->app->singleton(ChargeEfevooTokenAction::class);
        $this->app->singleton(RefundEfevooTransactionAction::class);
        $this->app->singleton(ChargeEfevooPaymentMethodAction::class);
        */

        // También mantener el servicio original disponible
        // $this->app->singleton(EfevooPayFactoryService::class);
    }

    public function boot(): void
    {
        RateLimiter::for('tax-profile-extract', function (Request $request) {
            return Limit::perMinute(5)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('clinical-interpreter-interpret', function (Request $request) {
            return Limit::perMinute(8)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('odessa-pre-enrollments-preview', function (Request $request) {
            return Limit::perMinute(5)->by((string) ($request->user()?->id ?: 'guest'));
        });

        RateLimiter::for('odessa-pre-enrollments-confirm', function (Request $request) {
            return Limit::perMinute(3)->by((string) ($request->user()?->id ?: 'guest'));
        });

        RateLimiter::for('odessa-pre-enrollments-murguia-register', function (Request $request) {
            return Limit::perMinute(3)->by((string) ($request->user()?->id ?: 'guest'));
        });

        RateLimiter::for('odessa-pre-enrollments-murguia-verify', function (Request $request) {
            return Limit::perMinute(5)->by((string) ($request->user()?->id ?: 'guest'));
        });

        RateLimiter::for('odessa-pre-enrollments-murguia-retry', function (Request $request) {
            return Limit::perMinute(3)->by((string) ($request->user()?->id ?: 'guest'));
        });

        RateLimiter::for('efevoopay-health', fn (Request $request) => $this->efevooPayLimit('health', $request));
        RateLimiter::for('efevoopay-tokenize', fn (Request $request) => $this->efevooPayLimit('tokenize', $request));
        RateLimiter::for('efevoopay-tokens', fn (Request $request) => $this->efevooPayLimit('tokens', $request));
        RateLimiter::for('efevoopay-payment', fn (Request $request) => $this->efevooPayLimit('payment', $request));
        RateLimiter::for('efevoopay-refund', fn (Request $request) => $this->efevooPayLimit('refund', $request));
        RateLimiter::for('efevoopay-search', fn (Request $request) => $this->efevooPayLimit('search', $request));
        RateLimiter::for('efevoopay-3ds-status', fn (Request $request) => $this->efevooPayLimit('3ds_status', $request));
        RateLimiter::for('efevoopay-recovery', fn (Request $request) => $this->efevooPayLimit('recovery', $request));
        RateLimiter::for('efevoopay-recovery-status', fn (Request $request) => $this->efevooPayLimit('recovery_status', $request));
        RateLimiter::for('efevoopay-recovery-sync', fn (Request $request) => $this->efevooPayLimit('recovery_sync', $request));

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

    private function efevooPayLimit(string $operation, Request $request): Limit
    {
        $settings = config("efevoopay.rate_limits.{$operation}", []);
        $maxAttempts = max(1, (int) ($settings['max_attempts'] ?? 10));
        $decayMinutes = max(1, (int) ($settings['decay_minutes'] ?? 1));

        return Limit::perMinutes($decayMinutes, $maxAttempts)
            ->by((string) ($request->user()?->id ?: $request->ip()));
    }
}
