<?php

namespace App\Support;

class EfevooPayPersistenceNormalizer
{
    private const MESSAGE_LIMIT = 240;

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public static function paymentResult(?array $payload, string $operation = 'payment', array $context = []): ?array
    {
        if ($payload === null) {
            return null;
        }

        $data = self::arrayAt($payload, 'data');
        $rawData = self::arrayAt($payload, 'raw.data');
        $source = $rawData !== [] ? $rawData : ($data !== [] ? $data : $payload);

        return self::withoutNulls([
            'operation' => $operation,
            'success' => self::boolOrNull($payload['success'] ?? null),
            'normalized_result' => self::normalizedResult($payload),
            'transaction_id' => self::stringValue(
                $payload['transaction_id']
                    ?? $payload['efevoo_transaction_id']
                    ?? $source['id']
                    ?? $source['numtxn']
                    ?? null
            ),
            'reference' => self::stringValue($payload['reference'] ?? $source['referencia'] ?? $source['reference'] ?? null),
            'response_code' => self::stringValue($payload['error_code'] ?? $payload['codigo'] ?? $source['codigo'] ?? null),
            'status' => self::stringValue($payload['status'] ?? $source['status'] ?? null),
            'authorization_code' => self::stringValue($payload['authorization_code'] ?? $source['numref'] ?? null),
            'message' => self::message($payload['message'] ?? $source['descripcion'] ?? $source['msg'] ?? null),
            'error_type' => self::stringValue($payload['error_type'] ?? null),
            'amount' => self::amount($payload['amount'] ?? $source['amount'] ?? $context['amount'] ?? null),
            'currency' => self::stringValue($payload['currency'] ?? $source['currency'] ?? $context['currency'] ?? null),
            'commission' => self::amount(
                $source['commission']
                    ?? $source['commission_amount']
                    ?? $source['commission_mxn']
                    ?? $source['transaction_fee']
                    ?? $source['fee']
                    ?? null
            ),
            'provider_http_status' => isset($payload['status']) && is_numeric($payload['status']) ? (int) $payload['status'] : null,
            'recorded_at' => now()->toISOString(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public static function refundResult(?array $payload, array $context = []): ?array
    {
        $normalized = self::paymentResult($payload, 'refund', $context);

        if ($normalized === null) {
            return null;
        }

        $data = self::arrayAt($payload ?? [], 'data');

        return self::withoutNulls(array_merge($normalized, [
            'refund_id' => self::stringValue(($payload['refund_id'] ?? null) ?: ($data['refund_id'] ?? null)),
            'original_transaction_id' => self::stringValue(
                $payload['original_transaction_id']
                    ?? $data['original_transaction_id']
                    ?? $context['original_transaction_id']
                    ?? null
            ),
        ]));
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public static function webhookPayload(?array $payload, string $event): ?array
    {
        if ($payload === null) {
            return null;
        }

        return self::withoutNulls([
            'operation' => 'webhook',
            'event' => self::stringValue($event),
            'transaction_id' => self::stringValue($payload['transaction_id'] ?? $payload['id'] ?? null),
            'reference' => self::stringValue($payload['reference'] ?? $payload['referencia'] ?? null),
            'response_code' => self::stringValue($payload['response_code'] ?? $payload['codigo'] ?? null),
            'status' => self::stringValue($payload['status'] ?? null),
            'message' => self::message($payload['message'] ?? $payload['descripcion'] ?? $payload['msg'] ?? null),
            'amount' => self::amount($payload['amount'] ?? null),
            'currency' => self::stringValue($payload['currency'] ?? null),
            'authorization_code' => self::stringValue($payload['authorization_code'] ?? $payload['numref'] ?? null),
            'received_at' => now()->toISOString(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public static function threeDsResult(?array $payload, string $operation = '3ds'): ?array
    {
        if ($payload === null) {
            return null;
        }

        $data = self::arrayAt($payload, 'data');
        $status = self::arrayAt($data, 'status');
        $challenge = self::arrayAt($data, 'payload');

        return self::withoutNulls([
            'operation' => $operation,
            'success' => self::boolOrNull($payload['success'] ?? null),
            'status_code' => self::stringValue($status['code'] ?? null),
            'status' => self::stringValue($challenge['status'] ?? $payload['status'] ?? null),
            'order_id' => self::stringValue($challenge['order_id'] ?? $payload['order_id'] ?? null),
            'message' => self::message($payload['message'] ?? $status['description'] ?? null),
            'error_type' => self::stringValue($payload['error_type'] ?? null),
            'checked_at' => now()->toISOString(),
        ]);
    }

    public static function exceptionSummary(\Throwable $exception, string $operation): array
    {
        return self::withoutNulls([
            'operation' => $operation,
            'success' => false,
            'normalized_result' => 'error',
            'message' => 'Error tecnico al procesar la operacion',
            'exception_class' => $exception::class,
            'recorded_at' => now()->toISOString(),
        ]);
    }

    public static function message(mixed $message): ?string
    {
        $safe = EfevooPayLogSanitizer::providerMessage($message);

        if ($safe === null) {
            return null;
        }

        return mb_substr($safe, 0, self::MESSAGE_LIMIT);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function normalizedResult(array $payload): ?string
    {
        if (array_key_exists('success', $payload)) {
            return $payload['success'] ? 'approved' : 'failed';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function arrayAt(array $payload, string $path): array
    {
        $value = data_get($payload, $path);

        return is_array($value) ? $value : [];
    }

    private static function stringValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_scalar($value)) {
            return mb_substr((string) $value, 0, 120);
        }

        return null;
    }

    private static function amount(mixed $value): float|int|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = str_replace([',', '$', 'MXN', 'mxn', ' '], '', $value);

            return is_numeric($normalized) ? (float) $normalized : null;
        }

        return null;
    }

    private static function boolOrNull(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function withoutNulls(array $payload): array
    {
        return array_filter($payload, fn ($value) => $value !== null && $value !== []);
    }
}
