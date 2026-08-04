<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\OnlinePharmacyPurchase;
use App\Services\Tracking\Tracking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Laravel\Cashier\Cashier;
use Stripe\StripeClient;
use App\Services\ConstanciaFiscalService;
use App\Contracts\Otp\OtpCodeGenerator;
use App\Services\Otp\AkubicaLoginOtpDecoyStore;
use App\Services\Otp\AkubicaLoginOtpService;
use App\Services\Otp\OtpAbuseKeyHasher;
use App\Services\Otp\OtpAbusePolicy;
use App\Services\Otp\OtpRateLimitService;
use App\Services\Otp\SecureOtpCodeGenerator;
use App\Http\Responses\Api\V1\OtpExceptionHttpMapper;
use App\Contracts\Otp\OtpDeliveryProvider;
use App\Services\Otp\Delivery\AkubicaSecureOtpDeliveryOrchestrator;
use App\Services\Otp\Delivery\ArrayOtpDeliveryReservationStore;
use App\Services\Otp\Delivery\FakeOtpDeliveryProvider;
use App\Services\Otp\Delivery\NullOtpDeliveryProvider;
use App\Services\Otp\Delivery\OtpDeliveryObservability;
use App\Services\Otp\Delivery\OtpDeliveryReservationStore;
use App\Services\Otp\Delivery\RedisOtpDeliveryReservationStore;
use App\Services\Otp\Delivery\VonageOtpDeliveryProvider;
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

        $this->app->bind(OtpCodeGenerator::class, SecureOtpCodeGenerator::class);
        $this->app->singleton(OtpAbuseKeyHasher::class);
        $this->app->singleton(\App\Services\Api\V1\Audit\AuditActorResolver::class);
        $this->app->singleton(\App\Services\Api\V1\Audit\AuditMetadataNormalizer::class, function () {
            return \App\Services\Api\V1\Audit\AuditMetadataNormalizer::fromConfig();
        });
        $this->app->singleton(\App\Services\Api\V1\Audit\AuditEventWriter::class);
        $this->app->singleton(\App\Services\Api\V1\Audit\AuthOtpAuditRecorder::class);
        $this->app->singleton(\App\Services\Api\V1\Audit\DocumentAccessAuditRecorder::class);
        $this->app->singleton(\App\Services\Api\V1\Audit\CartCheckoutAuditRecorder::class);
        $this->app->singleton(\App\Services\Api\V1\Audit\AppointmentConciergeAuditRecorder::class);
        $this->app->singleton(\App\Services\Audit\Business\BusinessAuditMetadataNormalizer::class, function () {
            return \App\Services\Audit\Business\BusinessAuditMetadataNormalizer::fromConfig();
        });
        $this->app->singleton(\App\Services\Audit\Business\BusinessAuditEventWriter::class);
        $this->app->bind(OtpRateLimitService::class);
        $this->app->bind(OtpAbusePolicy::class);
        $this->app->singleton(AkubicaLoginOtpDecoyStore::class);
        $this->app->bind(AkubicaLoginOtpService::class);
        $this->app->bind(\App\Services\Otp\StepUp\OtpStepUpGrantService::class);
        $this->app->bind(\App\Services\Otp\StepUp\AkubicaStepUpOtpService::class);
        $this->app->bind(\App\Services\Otp\StepUp\OtpSecureDownloadLinkService::class);
        $this->app->bind(\App\Services\Otp\StepUp\BearerStepUpEnforcement::class);
        $this->app->singleton(OtpExceptionHttpMapper::class);
        $this->app->singleton(\App\Services\Otp\Registration\EmailNormalizer::class);
        $this->app->singleton(\App\Services\Otp\Registration\MexicoPhoneNormalizer::class);
        $this->app->bind(\App\Services\Otp\Registration\RegistrationCollisionResolver::class);
        $this->app->singleton(\App\Services\Otp\Registration\AkubicaRegistrationPayloadCipher::class);
        $this->app->bind(\App\Services\Otp\Registration\AkubicaRegistrationIntentService::class);
        $this->app->singleton(\App\Services\Otp\Registration\AkubicaRegisterOtpDecoyStore::class);
        $this->app->bind(\App\Services\Otp\Registration\AkubicaRegisterOtpService::class);
        $this->app->singleton(OtpDeliveryObservability::class);
        $this->app->singleton(FakeOtpDeliveryProvider::class);
        $this->app->bind(OtpDeliveryReservationStore::class, function ($app) {
            if (config('otp.p0a.delivery.driver') === 'fake' || $app->environment('testing')) {
                if (config('otp.p0a.delivery.reservation_store', 'auto') === 'redis') {
                    return $app->make(RedisOtpDeliveryReservationStore::class);
                }

                return $app->make(ArrayOtpDeliveryReservationStore::class);
            }

            return $app->make(RedisOtpDeliveryReservationStore::class);
        });
        $this->app->bind(OtpDeliveryProvider::class, function ($app) {
            return match (config('otp.p0a.delivery.driver', 'null')) {
                'vonage' => $app->make(VonageOtpDeliveryProvider::class),
                'fake' => $app->make(FakeOtpDeliveryProvider::class),
                default => $app->make(NullOtpDeliveryProvider::class),
            };
        });
        $this->app->bind(AkubicaSecureOtpDeliveryOrchestrator::class);

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
        RateLimiter::for('akubica-otp', function (Request $request) {
            $identity = (string) (
                $request->input('email')
                ?: $request->input('phone')
                ?: $request->input('challenge_id')
                ?: ''
            );
            $key = $request->ip().'|'.$identity;

            return Limit::perMinute(5)->by($key);
        });

        Gate::define('assign-autorizador-role', function ($user): bool {
            return (bool) $user->administrator?->hasRole('superadmin');
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
