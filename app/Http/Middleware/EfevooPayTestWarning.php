<?php
// app/Http/Middleware/EfevooPayTestWarning.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EfevooPayTestWarning
{
    public function handle(Request $request, Closure $next)
    {
        if (config('efevoopay.environment') === 'test') {
            $response = $next($request);
            
            // Agregar header de advertencia
            $response->headers->set(
                'X-EfevooPay-Warning',
                'Test Environment - REAL charges may occur'
            );
            
            // Si es JSON response, agregar advertencia
            if ($response->headers->get('Content-Type') === 'application/json') {
                $content = json_decode($response->getContent(), true);
                if (is_array($content)) {
                    $content['_warning'] = '⚠ Ambiente de pruebas - Podrían generarse cargos REALES';
                    $response->setContent(json_encode($content));
                }
            }
            
            return $response;
        }
        
        return $next($request);
    }
}