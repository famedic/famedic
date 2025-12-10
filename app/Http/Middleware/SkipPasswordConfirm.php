<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SkipPasswordConfirm
{
    public function handle(Request $request, Closure $next)
    {
        // Deshabilitar temporalmente el middleware RequirePassword
        app('router')->aliasMiddleware('password.confirm', function ($request, $next) {
            return $next($request);
        });
        
        return $next($request);
    }
}