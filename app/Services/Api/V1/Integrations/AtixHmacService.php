<?php

namespace App\Services\Api\V1\Integrations;

use App\Http\Responses\ApiResponse;
use App\Models\AtixSandboxNonce;
use App\Services\Api\V1\Integrations\AtixAuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

final class AtixHmacService
{
    public function __construct(private readonly AtixAuditRecorder $audit) {}

    public function authenticate(Request $request): ?JsonResponse
    {
        if (! $request->hasHeader('X-Correlation-ID')) {
            return $this->failure($request, 'ATIX_CORRELATION_ID_REQUIRED', 'X-Correlation-ID es obligatorio.', 422, false);
        }
        $keyId = (string) $request->header('X-Integration-Key-Id', '');
        $timestamp = (string) $request->header('X-Timestamp', '');
        $nonce = (string) $request->header('X-Nonce', '');
        $signature = strtolower((string) $request->header('X-Signature', ''));
        $secret = (string) (config('atix.sandbox.keys', [])[$keyId] ?? '');

        if ($keyId === '' || $secret === '') {
            return $this->failure($request, 'ATIX_INTEGRATION_UNAUTHORIZED', 'Integracion no autorizada.', 401, false);
        }
        if (! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > (int) config('atix.sandbox.timestamp_tolerance_seconds', 300)) {
            return $this->failure($request, 'ATIX_TIMESTAMP_EXPIRED', 'Timestamp invalido o expirado.', 401, false);
        }
        if (! preg_match('/^[A-Za-z0-9._-]{8,128}$/', $nonce) || ! preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return $this->failure($request, 'ATIX_SIGNATURE_INVALID', 'Firma invalida.', 401, false);
        }

        $canonical = implode("\n", [
            strtoupper($request->method()),
            '/api/v1/integrations/atix/phone-lookups',
            $timestamp,
            $nonce,
            hash('sha256', $request->getContent()),
        ]);
        $expected = hash_hmac((string) config('atix.sandbox.algorithm', 'sha256'), $canonical, $secret);
        if (! hash_equals($expected, $signature)) {
            return $this->failure($request, 'ATIX_SIGNATURE_INVALID', 'Firma invalida.', 401, false);
        }

        $integrationHash = hash('sha256', $keyId);
        $nonceHash = hash('sha256', $nonce);
        $existing = AtixSandboxNonce::query()
            ->where('integration_key_hash', $integrationHash)
            ->where('nonce_hash', $nonceHash)
            ->first();
        if ($existing !== null && $existing->expires_at?->isFuture()) {
            return $this->failure($request, 'ATIX_NONCE_REPLAY', 'Nonce repetido.', 409, false);
        }
        if ($existing !== null) {
            $existing->delete();
        }

        try {
            AtixSandboxNonce::create([
                'integration_key_hash' => $integrationHash,
                'nonce_hash' => $nonceHash,
                'expires_at' => now()->addSeconds((int) config('atix.sandbox.nonce_ttl_seconds', 300)),
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            $active = AtixSandboxNonce::query()
                ->where('integration_key_hash', $integrationHash)
                ->where('nonce_hash', $nonceHash)
                ->where('expires_at', '>', now())
                ->exists();
            if ($active) {
                return $this->failure($request, 'ATIX_NONCE_REPLAY', 'Nonce repetido.', 409, false);
            }

            report($exception);
            return $this->failure($request, 'ATIX_INFRASTRUCTURE_UNAVAILABLE', 'Servicio temporalmente no disponible.', 503, true);
        }

        $request->attributes->set('atix.integration_key_hash', $integrationHash);
        return null;
    }

    private function failure(Request $request, string $code, string $message, int $status, bool $retryable): JsonResponse
    {
        $this->audit->record($request, 'rejected', $status, $code);
        return ApiResponse::error($code, $message, $status, null, null, $retryable);
    }
}
