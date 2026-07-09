<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Valida el secreto de webhooks Zoho SalesIQ (header X-Famedic-Zoho-Secret).
 * No loguea el payload ni el secreto.
 */
class VerifyZohoSalesIqWebhookSecret
{
    public const HEADER = 'X-Famedic-Zoho-Secret';

    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('services.zoho.salesiq.webhook_secret');
        $configured = is_string($configured) ? trim($configured) : '';

        if ($configured === '') {
            Log::warning('Zoho SalesIQ webhook rejected: secret not configured', [
                'path' => $request->path(),
            ]);

            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        $provided = $request->header(self::HEADER);

        if (! is_string($provided) || $provided === '' || ! hash_equals($configured, $provided)) {
            Log::warning('Zoho SalesIQ webhook rejected: invalid secret', [
                'path' => $request->path(),
                'has_header' => is_string($provided) && $provided !== '',
            ]);

            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
