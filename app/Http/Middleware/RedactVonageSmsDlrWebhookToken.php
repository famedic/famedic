<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prevents the Vonage DLR webhook token from appearing in Laravel log context.
 */
final class RedactVonageSmsDlrWebhookToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->route('token');
        if (is_string($token) && $token !== '') {
            Log::shareContext([
                'vonage_sms_dlr_webhook' => true,
                'request_path' => '/webhooks/vonage/sms/delivery/[REDACTED]',
            ]);

            $request->attributes->set('vonage_dlr_token_redacted', true);
        }

        return $next($request);
    }
}
