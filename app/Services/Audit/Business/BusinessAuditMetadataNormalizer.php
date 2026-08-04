<?php

namespace App\Services\Audit\Business;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Allowlist-first metadata normalizer for business audit (Block 6A).
 *
 * Independent from API V1 AuditMetadataNormalizer. When JSON exceeds max
 * bytes the entire metadata object is discarded (fail-soft).
 */
final class BusinessAuditMetadataNormalizer
{
    private const REJECT = "\0REJECT\0";

    /**
     * Substring tokens that mark a key as sensitive (case-insensitive).
     *
     * @var list<string>
     */
    private const SENSITIVE_KEY_TOKENS = [
        'password',
        'token',
        'secret',
        'authorization',
        'bearer',
        'cookie',
        'session',
        'email',
        'phone',
        'mobile',
        'address',
        'rfc',
        'curp',
        'fiscal',
        'card',
        'bin',
        'cvv',
        'payload',
        'clinical',
        'diagnosis',
        'requisition',
        'coupon_code',
        'promo_code',
    ];

    /**
     * Exact forbidden key names (snake_case, after normalization).
     *
     * @var list<string>
     */
    private const FORBIDDEN_KEYS = [
        'password',
        'token',
        'secret',
        'authorization',
        'bearer',
        'cookie',
        'cookies',
        'session',
        'session_id',
        'email',
        'phone',
        'mobile',
        'name',
        'address',
        'rfc',
        'curp',
        'tax',
        'fiscal',
        'card',
        'bin',
        'cvv',
        'account',
        'reference',
        'payment_id',
        'transaction_id',
        'authorization_code',
        'coupon_code',
        'promo_code',
        'payload',
        'request',
        'response',
        'body',
        'headers',
        'notes',
        'clinical',
        'diagnosis',
        'study_name',
        'test_name',
        'requisition',
        'external_id',
        'request_body',
        'response_body',
        'exception_message',
        'stack_trace',
        'trace',
        'ip',
        'user_agent',
        'patient_data',
        'clinical_details',
        'razon_social',
        'payment_token',
        'payment_url',
        'payment_payload',
        'gateway_transaction_id',
        'provider_transaction_id',
    ];

    public function __construct(
        private readonly int $maxBytes = 2048,
        private readonly int $maxDepth = 2,
        private readonly int $maxKeys = 32,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            maxBytes: (int) config('business_audit.max_metadata_bytes', 2048),
            maxDepth: (int) config('business_audit.max_metadata_depth', 2),
            maxKeys: (int) config('business_audit.max_metadata_keys', 32),
        );
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>|null
     */
    public function normalize(string $eventName, ?array $metadata): ?array
    {
        if ($metadata === null || $metadata === []) {
            return null;
        }

        $allowlist = BusinessAuditEventDefinitions::allowedMetadataKeys($eventName);
        if ($allowlist === []) {
            $this->logDiscarded($eventName, 'empty_allowlist');

            return null;
        }

        $allowedSet = array_fill_keys($allowlist, true);
        $cleaned = [];
        $rejectedKeys = [];

        foreach ($metadata as $rawKey => $value) {
            if (count($cleaned) >= $this->maxKeys) {
                $rejectedKeys[] = '(max_keys)';

                break;
            }

            if (! is_string($rawKey) && ! is_int($rawKey)) {
                $rejectedKeys[] = '(non_string_key)';

                continue;
            }

            $key = $this->toSnakeCase((string) $rawKey);
            if ($key === '') {
                $rejectedKeys[] = (string) $rawKey;

                continue;
            }

            if (! isset($allowedSet[$key])) {
                $rejectedKeys[] = $key;

                continue;
            }

            if ($this->isForbiddenKey($key) || $this->keyLooksSensitive($key)) {
                $rejectedKeys[] = $key;

                continue;
            }

            $normalizedValue = $this->normalizeValue($value, 1);
            if ($normalizedValue === self::REJECT) {
                $rejectedKeys[] = $key;

                continue;
            }

            $cleaned[$key] = $normalizedValue;
        }

        if ($cleaned === []) {
            if ($rejectedKeys !== []) {
                $this->logDiscarded($eventName, 'all_keys_rejected', $rejectedKeys);
            }

            return null;
        }

        $encoded = json_encode($cleaned, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $this->logDiscarded($eventName, 'json_encode_failed');

            return null;
        }

        if (strlen($encoded) > $this->maxBytes) {
            $this->logDiscarded($eventName, 'max_bytes_exceeded', [
                'byte_length' => strlen($encoded),
                'max_bytes' => $this->maxBytes,
            ]);

            return null;
        }

        if ($rejectedKeys !== []) {
            $this->logDiscarded($eventName, 'keys_filtered', $rejectedKeys);
        }

        return $cleaned;
    }

    private function normalizeValue(mixed $value, int $depth): mixed
    {
        if ($depth > $this->maxDepth) {
            return self::REJECT;
        }

        if ($value instanceof Model
            || $value instanceof Request
            || $value instanceof Response
            || $value instanceof SymfonyResponse
            || $value instanceof UploadedFile
            || $value instanceof Throwable
        ) {
            return self::REJECT;
        }

        if (is_object($value) || is_resource($value)) {
            return self::REJECT;
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            if (is_float($value) && (is_nan($value) || is_infinite($value))) {
                return self::REJECT;
            }

            return $value;
        }

        if (is_string($value)) {
            if (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
                return self::REJECT;
            }

            if (mb_strlen($value) > 256) {
                return self::REJECT;
            }

            return $value;
        }

        if (! is_array($value)) {
            return self::REJECT;
        }

        if (count($value) > 32) {
            return self::REJECT;
        }

        $isList = array_is_list($value);
        $out = [];

        foreach ($value as $k => $item) {
            if ($isList) {
                $child = $this->normalizeValue($item, $depth + 1);
                if ($child === self::REJECT || is_array($child)) {
                    return self::REJECT;
                }
                $out[] = $child;

                continue;
            }

            if (! is_string($k) && ! is_int($k)) {
                return self::REJECT;
            }

            $childKey = $this->toSnakeCase((string) $k);
            if ($childKey === ''
                || $this->isForbiddenKey($childKey)
                || $this->keyLooksSensitive($childKey)
            ) {
                continue;
            }

            $child = $this->normalizeValue($item, $depth + 1);
            if ($child === self::REJECT || is_array($child)) {
                return self::REJECT;
            }

            $out[$childKey] = $child;
        }

        return $out;
    }

    private function toSnakeCase(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }

        $key = str_replace(['-', ' '], '_', $key);
        $key = preg_replace('/([a-z])([A-Z])/', '$1_$2', $key) ?? $key;
        $key = strtolower($key);
        $key = preg_replace('/[^a-z0-9_]/', '', $key) ?? $key;
        $key = preg_replace('/_+/', '_', $key) ?? $key;

        return trim($key, '_');
    }

    private function isForbiddenKey(string $key): bool
    {
        return in_array($key, self::FORBIDDEN_KEYS, true);
    }

    private function keyLooksSensitive(string $key): bool
    {
        foreach (self::SENSITIVE_KEY_TOKENS as $token) {
            if ($key === $token || str_contains($key, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>|array<string, mixed>  $detail
     */
    private function logDiscarded(string $eventName, string $reason, array $detail = []): void
    {
        $payload = [
            'event_name' => $eventName,
            'reason' => $reason,
        ];

        if ($detail !== [] && array_is_list($detail)) {
            $payload['rejected_keys'] = array_slice(array_values($detail), 0, 32);
        } elseif ($detail !== []) {
            $safe = [];
            foreach ($detail as $k => $v) {
                if (is_string($k) && (is_int($v) || is_string($v) || is_bool($v))) {
                    $safe[$k] = $v;
                }
            }
            $payload['detail'] = $safe;
        }

        Log::info('business_audit_metadata_discarded', $payload);
    }
}
