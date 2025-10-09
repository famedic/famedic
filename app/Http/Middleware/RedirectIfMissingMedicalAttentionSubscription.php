<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfMissingMedicalAttentionSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->customer?->medical_attention_subscription_expires_at?->isFuture()) {
            return $next($request);
        }

        return redirect()->route('medical-attention');
    }
}
