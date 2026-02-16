<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Verify3dsSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $sessionId = $request->route('sessionId');
        
        if ($sessionId) {
            $request->merge(['_3ds_session_id' => $sessionId]);
        }

        return $next($request);
    }
}