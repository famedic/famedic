<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe acceso a usuarios con rol Spatie id = 1 (super administrador).
 * Ajusta la consulta si tu rol maestro usa otro id.
 */
class EnsureUserHasSuperAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->administrator) {
            abort(403);
        }

        if (! $user->administrator->roles()->where('roles.id', 1)->exists()) {
            abort(403);
        }

        return $next($request);
    }
}
