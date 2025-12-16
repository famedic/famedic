<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Auth\Middleware\RequirePassword;

class ExcludePasswordConfirm
{
    public function handle(Request $request, Closure $next, ...$excludeRoutes)
    {
        // Rutas que no requieren password confirm
        $excludedRoutes = [
            'tax-profiles.extract-data',
            'test.service',
            'debug.extract-data',
        ];
        
        // Verificar si la ruta actual está excluida
        $routeName = $request->route()->getName();
        
        if (in_array($routeName, $excludedRoutes)) {
            return $next($request);
        }
        
        // Para todas las demás rutas, aplicar password.confirm
        return app(RequirePassword::class)->handle($request, $next);
    }
}