<?php

namespace App\Providers;

use App\Services\EfevooPayService;
use Illuminate\Support\ServiceProvider;

class EfevooPayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EfevooPayService::class, function ($app) {
            return new EfevooPayService();
        });
        
        // También registrar con alias para Facade si lo vas a usar
        $this->app->alias(EfevooPayService::class, 'efevoopay');
    }

    public function boot(): void
    {
        // Publicar configuración si es necesario
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/efevoopay.php' => config_path('efevoopay.php'),
            ], 'efevoopay-config');
        }
    }
}