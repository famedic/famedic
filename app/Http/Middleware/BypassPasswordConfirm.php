<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;

class BypassPasswordConfirm
{
    public function handle(Request $request, Closure $next, ...$exceptions)
    {
        // Rutas que no requieren confirmación de contraseña
        $excludedRoutes = [
            'tax-profiles.extract-data',
            'test.service',
            'debug.extract-data',
        ];

        if (in_array($request->route()->getName(), $excludedRoutes)) {
            return $next($request);
        }

        // Para todas las demás rutas, aplicar el middleware normal
        return app(RequirePassword::class)->handle($request, $next);
    }
}