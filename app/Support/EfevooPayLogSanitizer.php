<?php

namespace App\Support;

use Throwable;

class EfevooPayLogSanitizer
{
    private const ALLOWED_KEYS = [
        'user_id',
        'customer_id',
        'cart_id',
        'purchase_id',
        'payment_attempt_id',
        'attempt_id',
        'efevoo_3ds_session_id',
        'session_id',
        'support_reference',
        'reference',
        'merchant_reference',
        'response_code',
        'processor_code',
        'http_status',
        'status',
        'duration_ms',
        'operation',
        'exception_class',
        'transaction_id',
        'gateway_transaction_id',
        'processor_transaction_id',
        'token_id',
        'efevoo_token_id',
        'environment',
        'error_type',
        'method',
        'simulated',
    ];

    private const SENSITIVE_KEYS = [
        'authorization',
        'api_key',
        'api_secret',
        'card_number',
        'card_token',
        'cav',
        'client_token',
        'cookie',
        'cvv',
        'encrypt',
        'pan',
        'password',
        'secret',
        'token',
        'token3ds',
        'token_3dsecure',
        'token_usuario',
        'track',
        'track2',
    ];

    /**
     * Build a log context from an explicit allowlist.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $extraAllowed
     * @return array<string, mixed>
     */
    public static function context(array $data, array $extraAllowed = []): array
    {
        $allowed = array_fill_keys(array_merge(self::ALLOWED_KEYS, $extraAllowed), true);
        $context = [];

        foreach ($data as $key => $value) {
            $key = (string) $key;

            if (! isset($allowed[$key]) || self::isSensitiveKey($key)) {
                continue;
            }

            $context[$key] = self::safeScalar($value);
        }

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    public static function exception(Throwable $e): array
    {
        return [
            'exception_class' => $e::class,
        ];
    }

    public static function providerMessage(mixed $message): ?string
    {
        if (! is_scalar($message)) {
            return null;
        }

        $message = trim((string) $message);
        if ($message === '') {
            return null;
        }

        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if (stripos($message, $sensitiveKey) !== false) {
                return 'Respuesta del proveedor omitida por seguridad.';
            }
        }

        $message = preg_replace('/(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)/', '[redacted-pan]', $message) ?? $message;
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=-]+/i', '[redacted-token]', $message) ?? $message;
        $message = preg_replace('/\b[A-Za-z0-9+\/=_-]{32,}\b/', '[redacted-token]', $message) ?? $message;

        return mb_substr($message, 0, 180);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    public static function providerResult(array $response): array
    {
        $data = $response['data'] ?? $response;

        return self::context([
            'http_status' => $response['status'] ?? null,
            'status' => is_array($data) ? ($data['status']['code'] ?? $data['status'] ?? null) : null,
            'response_code' => is_array($data) ? ($data['codigo'] ?? $data['code'] ?? null) : null,
            'processor_code' => is_array($data) ? ($data['codigo'] ?? $data['code'] ?? null) : null,
            'transaction_id' => is_array($data) ? ($data['id'] ?? $data['transaction_id'] ?? null) : null,
            'reference' => is_array($data) ? ($data['reference'] ?? $data['referencia'] ?? null) : null,
        ]);
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if ($normalized === $sensitiveKey || str_contains($normalized, $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }

    private static function safeScalar(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            $value = trim((string) $value);

            return mb_substr($value, 0, 160);
        }

        return '[non_scalar]';
    }
}
