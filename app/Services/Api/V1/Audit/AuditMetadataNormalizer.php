<?php

namespace App\Services\Api\V1\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Allowlist + depth/size/type guards for audit metadata.
 *
 * Decision when JSON exceeds max bytes: discard the entire metadata object
 * (fail-soft) and emit a sanitized log — never truncate values or JSON silently.
 */
final class AuditMetadataNormalizer
{
    private const REJECT = "\0REJECT\0";

    /**
     * Substring tokens that mark a key as sensitive (case-insensitive).
     * Defense-in-depth; does not replace the per-event allowlist.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEY_TOKENS = [
        'token',
        'secret',
        'password',
        'otp',
        'code',
        'authorization',
        'bearer',
        'cookie',
        'key',
        'grant',
    ];

    /**
     * Exact forbidden key names (snake_case, after normalization).
     *
     * @var list<string>
     */
    private const FORBIDDEN_KEYS = [
        'authorization',
        'bearer',
        'password',
        'otp',
        'code',
        'coupon_code',
        'promo_code',
        'idempotency_key',
        'secure_link_token',
        'secure_link',
        'step_up_grant',
        'x_step_up_grant',
        'grant_public_id',
        'cookie',
        'cookies',
        'app_key',
        'request_body',
        'response_body',
        'exception_message',
        'stack_trace',
        'trace',
        'pdf',
        'xml',
        'document',
        'document_body',
        'card',
        'cvv',
        'expiration',
        'payment_token',
        'payment_url',
        'payment_payload',
        'phone',
        'email',
        'name',
        'address',
        'rfc',
        'razon_social',
        'patient_data',
        'clinical_details',
        'test_name',
    ];

    public function __construct(
        private readonly int $maxBytes = 2048,
        private readonly int $maxDepth = 2,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            maxBytes: (int) config('api_v1.audit.max_metadata_bytes', 2048),
            maxDepth: (int) config('api_v1.audit.max_metadata_depth', 2),
        );
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>|null  null when empty or discarded
     */
    public function normalize(string $eventName, ?array $metadata): ?array
    {
        if ($metadata === null || $metadata === []) {
            return null;
        }

        $allowlist = AuditEventDefinitions::allowedMetadataKeys($eventName);
        if ($allowlist === []) {
            $this->logDiscarded($eventName, 'empty_allowlist');

            return null;
        }

        $allowedSet = array_fill_keys($allowlist, true);
        $cleaned = [];
        $rejectedKeys = [];

        foreach ($metadata as $rawKey => $value) {
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
            // Decision: discard entire metadata — never truncate silently.
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

        if (is_object($value)) {
            return self::REJECT;
        }

        if (is_resource($value)) {
            return self::REJECT;
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            if (is_float($value) && (is_nan($value) || is_infinite($value))) {
                return self::REJECT;
            }

            return $value;
        }

        if (is_string($value)) {
            if (! mb_check_encoding($value, 'UTF-8')) {
                return self::REJECT;
            }

            if (str_contains($value, "\0")) {
                return self::REJECT;
            }

            return $value;
        }

        if (! is_array($value)) {
            return self::REJECT;
        }

        $isList = array_is_list($value);
        $out = [];

        foreach ($value as $k => $item) {
            if ($isList) {
                $child = $this->normalizeValue($item, $depth + 1);
                if ($child === self::REJECT || is_array($child)) {
                    // Flat arrays of scalars only — nested arrays rejected.
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
                // Associative children must be scalars at this depth.
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

        Log::info('akubica_audit_metadata_discarded', $payload);
    }
}
