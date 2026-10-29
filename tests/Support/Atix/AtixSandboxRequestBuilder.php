<?php

namespace Tests\Support\Atix;

final class AtixSandboxRequestBuilder
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public static function headers(
        array $payload,
        string $secret = 'sandbox-secret',
        string $keyId = 'sandbox-test',
        string $nonce = 'nonce-atix-default',
        string $idempotency = 'idem-atix-default',
        ?string $timestamp = null,
        string $correlationId = 'corr-atix-test',
    ): array {
        $timestamp ??= (string) now()->timestamp;
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $canonical = implode("\n", [
            'POST',
            '/api/v1/integrations/atix/phone-lookups',
            $timestamp,
            $nonce,
            hash('sha256', (string) $body),
        ]);

        return [
            'X-Integration-Key-Id' => $keyId,
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => hash_hmac('sha256', $canonical, $secret),
            'X-Correlation-ID' => $correlationId,
            'Idempotency-Key' => $idempotency,
        ];
    }
}
