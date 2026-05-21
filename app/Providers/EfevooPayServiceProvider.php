<?php

namespace App\Providers;

use App\Contracts\EfevooPayGateway;
use App\Services\EfevooPay\MockEfevooPayGateway;
use App\Services\EfevooPayFactoryService;
use App\Services\EfevooPayService;
use Illuminate\Support\ServiceProvider;

class EfevooPayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EfevooPayGateway::class, function ($app) {
            if ($app->environment('production')) {
                return new EfevooPayService;
            }

            return new MockEfevooPayGateway;
        });

        // En no-producción resuelve al mock para evitar cargos reales en cualquier flujo legacy.
        $this->app->singleton(EfevooPayService::class, function ($app) {
            return $app->make(EfevooPayGateway::class);
        });

        $this->app->singleton(EfevooPayFactoryService::class, function ($app) {
            return new EfevooPayFactoryService($app->make(EfevooPayGateway::class));
        });

        $this->app->alias(EfevooPayGateway::class, 'efevoopay.gateway');
        $this->app->alias(EfevooPayService::class, 'efevoopay');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/efevoopay.php' => config_path('efevoopay.php'),
            ], 'efevoopay-config');
        }
    }
}
