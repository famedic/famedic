<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\OnlinePharmacyPurchase;
use App\Services\Tracking\Tracking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Stripe\StripeClient;
use App\Services\ConstanciaFiscalService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StripeClient::class, function ($app) {
            return new StripeClient(config('services.stripe.secret'));
        });

        $this->app->singleton(Tracking::class, function ($app) {
            return new Tracking;
        });

        // Registrar servicios de EfevooPay
        $this->app->singleton(\App\Services\TOTPService::class);
        $this->app->singleton(\App\Services\EfevooPayService::class);
        $this->app->singleton(\App\Services\WebSocketService::class);
        $this->app->singleton(\App\Services\PaymentMethodService::class); 
        
        // Registrar actions
        $this->app->singleton(\App\Actions\Payments\CreateEfevooPayOrder::class);
        $this->app->singleton(\App\Actions\Payments\CheckEfevooPayStatus::class);
        $this->app->singleton(\App\Actions\Payments\ProcessEfevooPayCallback::class);

        if ($this->app->environment('local', 'testing')) {
            $this->app->register(DuskServiceProvider::class);
        }

        $this->app->singleton(ConstanciaFiscalService::class, function ($app) {
            return new ConstanciaFiscalService();
        });


    }

    public function boot(): void
    {
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
        
        // Registrar eventos y listeners
        $this->registerEvents();
    }
    
    /**
     * Registrar eventos y listeners para EfevooPay
     */
    protected function registerEvents(): void
    {
        Event::listen(
            \App\Events\PaymentStatusUpdated::class,
            \App\Listeners\UpdateTransactionStatus::class
        );
        
        Event::listen(
            \App\Events\PaymentStatusUpdated::class,
            \App\Listeners\SendPaymentNotification::class
        );
        
        // También puedes usar el método listen con array
        // Event::listen([
        //     \App\Events\PaymentStatusUpdated::class => [
        //         \App\Listeners\UpdateTransactionStatus::class,
        //         \App\Listeners\SendPaymentNotification::class,
        //     ],
        // ]);
    }
}