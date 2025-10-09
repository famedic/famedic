<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Documentation;

class EnsureDocumentationIsAccepted
{
    public function handle(Request $request, Closure $next): Response
    {
        $documentation = Documentation::latest()->first();

        if (!$documentation || !$request->user()) {
            return $next($request);
        }

        if (
            blank($request->user()->documentation_accepted_at)
            || $request->user()->documentation_accepted_at->isBefore($documentation->updated_at)
        ) {
            if (!$request->routeIs('documentation.accept', 'documentation.accept.store')) {
                return redirect()->route('documentation.accept');
            }
        } else {
            if ($request->routeIs('documentation.accept', 'documentation.accept.store')) {
                return redirect()->route('home');
            }
        }

        return $next($request);
    }
}
