<?php

namespace App\Services\Api\V1\Integrations;

use App\Http\Responses\ApiResponse;
use App\Models\AtixSandboxNonce;
use App\Services\Api\V1\Integrations\AtixAuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\UniqueConstraintViolationException;

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
        $tolerance = config('atix.sandbox.timestamp_tolerance_seconds', 300);
        $configuredTtl = config('atix.sandbox.nonce_ttl_seconds', 300);
        if (! is_int($tolerance) || $tolerance < 1 || $tolerance > 900
            || ! is_int($configuredTtl) || $configuredTtl < 1 || $configuredTtl > 86400) {
            return $this->failure($request, 'ATIX_INFRASTRUCTURE_UNAVAILABLE', 'Servicio temporalmente no disponible.', 503, true);
        }

        // Inclusive integer-second acceptance needs one additional second, not
        // an assumed operational clock-skew allowance. Never shorten a larger TTL.
        $retention = max($configuredTtl, 2 * $tolerance + 1);
        $receivedAt = now()->toImmutable();
        $receivedTimestamp = $receivedAt->getTimestamp();
        // The existing MySQL TIMESTAMP column must represent the full horizon.
        if ($receivedTimestamp < 1 || $receivedTimestamp > 2147483647 - $retention) {
            return $this->failure($request, 'ATIX_INFRASTRUCTURE_UNAVAILABLE', 'Servicio temporalmente no disponible.', 503, true);
        }
        $numericTimestamp = ltrim($timestamp, '0');
        $integerLimit = (string) PHP_INT_MAX;
        if (! ctype_digit($timestamp)
            || strlen($numericTimestamp) > strlen($integerLimit)
            || (strlen($numericTimestamp) === strlen($integerLimit) && strcmp($numericTimestamp, $integerLimit) > 0)
            || abs($receivedTimestamp - (int) $numericTimestamp) > $tolerance) {
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
        try {
            // INSERT + the existing unique index is the claim. Do not SELECT,
            // delete or recycle expired rows: wall-clock rollback must not make
            // an already accepted nonce usable again for an active key.
            AtixSandboxNonce::create([
                'integration_key_hash' => $integrationHash,
                'nonce_hash' => $nonceHash,
                'expires_at' => $receivedAt->addSeconds($retention),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->failure($request, 'ATIX_NONCE_REPLAY', 'Nonce repetido.', 409, false);
        } catch (\PDOException) {
            // No follow-up database read on an unavailable/failed connection.
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
