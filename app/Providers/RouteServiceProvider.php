<?php
// app/Providers/RouteServiceProvider.php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            // ✅ RUTAS API (con autenticación)
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            // ✅ RUTAS WEB (con sesión)
            Route::middleware('web')
                ->group(base_path('routes/web.php'));

            // ✅ RUTAS WEBHOOKS (sin autenticación, sin CSRF)
            Route::middleware(['webhooks']) // Nuestro middleware personalizado
                ->group(base_path('routes/webhooks.php'));
        });
    }
}