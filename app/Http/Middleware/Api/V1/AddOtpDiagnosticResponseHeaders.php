<?php

namespace App\Http\Middleware\Api\V1;

use App\Services\Otp\Diagnostics\OtpDiagnosticContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AddOtpDiagnosticResponseHeaders
{
    public function __construct(
        private readonly OtpDiagnosticContext $diagnostics,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->diagnostics->reset();
        $this->diagnostics->markRequestReceived();

        /** @var Response $response */
        $response = $next($request);

        if (! $this->authorized($request)) {
            return $response;
        }

        $this->applyFallbackClassification($response);
        $response->headers->set('Cache-Control', 'no-store, private');

        foreach ($this->diagnostics->headersFor($response) as $header => $value) {
            $response->headers->set($header, $value);
        }

        return $response;
    }

    private function authorized(Request $request): bool
    {
        if (! app()->environment(['local', 'staging'])) {
            return false;
        }

        if (! (bool) config('otp.diagnostic_response_headers.enabled', false)) {
            return false;
        }

        $requested = strtolower(trim((string) $request->headers->get('X-OTP-Diagnostic', '')));
        if (! in_array($requested, ['1', 'true', 'yes'], true)) {
            return false;
        }

        $expected = (string) config('otp.diagnostic_response_headers.token', '');
        $provided = (string) $request->headers->get('X-OTP-Diagnostic-Token', '');

        if (strlen($expected) < 32 || strlen($provided) < 32) {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    private function applyFallbackClassification(Response $response): void
    {
        $errorCode = null;
        $decoded = json_decode((string) $response->getContent(), true);
        if (is_array($decoded) && is_array($decoded['error'] ?? null)) {
            $errorCode = is_string($decoded['error']['code'] ?? null)
                ? $decoded['error']['code']
                : null;
        }

        if ($response->getStatusCode() === 429) {
            $this->diagnostics->markRateLimited();

            return;
        }

        match ($errorCode) {
            'FEATURE_DISABLED',
            'OTP_CONFIGURATION_INVALID' => $this->diagnostics->markConfigurationError(),
            'OTP_TEMPORARY_UNAVAILABLE' => $this->diagnostics->markPreDeliveryFailure(),
            'DELIVERY_FAILED' => $this->diagnostics->markDeliveryRejected(),
            'IDEMPOTENCY_OPERATION_UNCERTAIN' => $this->diagnostics->markDeliveryUncertain(),
            default => null,
        };
    }
}
