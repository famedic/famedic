<?php

namespace App\Support;

use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Models\PaymentAuthenticationAttempt;

class PaymentAuthenticationEfevooPayMonetaryTracer
{
    public const OP_GET_LINK = 'payments3DS_GetLink';

    public const OP_GET_STATUS = 'payments3DS_GetStatus';

    public const OP_TOKENIZE = 'getTokenize';

    public function __construct(
        private PaymentAuthenticationAttemptRecorder $recorder
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        PaymentAuthenticationAttempt $attempt,
        PaymentAuthenticationAttemptEventType $eventType,
        string $operation,
        array $metadata = []
    ): void {
        $payload = $this->sanitizeMetadata(array_merge([
            'operation' => $operation,
            'detected_by' => 'famedic',
        ], $metadata));

        $attributes = [
            'source' => 'backend',
            'dedupe_key' => $eventType->value.':'.$operation.':'.($payload['call_number'] ?? $attempt->fresh()->external_call_count + 1).':'.($payload['stage'] ?? 'default'),
            'metadata' => $payload,
            'duration_ms' => $payload['duration_ms'] ?? null,
            'http_status' => $payload['http_status'] ?? null,
            'provider_code' => isset($payload['provider_code']) ? (string) $payload['provider_code'] : null,
        ];

        if (str_ends_with($eventType->value, '_started')) {
            $attributes['external_operation'] = $operation;
        }

        $this->recorder->record($attempt, $eventType, $attributes);
    }

    public function recordDuplicateBlocked(
        PaymentAuthenticationAttempt $attempt,
        string $operation,
        string $reason,
        ?int $sessionId = null
    ): void {
        $this->record($attempt, PaymentAuthenticationAttemptEventType::DuplicateExternalCallBlocked, $operation, [
            'reason' => $reason,
            'stage' => 'concurrent_guard',
            'session_id' => $sessionId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordPossibleDuplicate(PaymentAuthenticationAttempt $attempt, array $metadata): void
    {
        $this->record($attempt, PaymentAuthenticationAttemptEventType::PossibleDuplicateVerificationOperation, 'correlation', $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function sanitizeMetadata(array $metadata): array
    {
        $allowed = array_intersect_key($metadata, array_flip(PaymentAuthenticationAttemptRecorder::MONETARY_METADATA_ALLOWLIST));

        foreach ($allowed as $key => $value) {
            if (in_array($key, ['provider_order_id', 'processor_transaction_id'], true) && is_string($value)) {
                $allowed[$key] = $this->maskIdentifier($value);
            }
        }

        return $allowed;
    }

    public function maskIdentifier(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $length = strlen($value);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', max(0, $length - 4)).substr($value, -4);
    }
}
