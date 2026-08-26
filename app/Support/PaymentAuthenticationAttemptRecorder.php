<?php

namespace App\Support;

use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationAttemptEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentAuthenticationAttemptRecorder
{
    private const SOURCES = ['frontend', 'backend', 'efevoopay', 'acs', 'polling', 'system', 'admin'];

    public const METADATA_ALLOWLIST = [
        'session_id',
        'poll_number',
        'retry_attempt_number',
        'previous_attempt_id',
        'external_operation',
        'exception_class',
        'timeout_stage',
        'legacy_session',
        'response_received',
        'request_dispatched',
        'failure_stage',
        'exception_category',
        'detected_by',
        'context_uuid',
        'context_type',
        'return_route_name',
        'cart_id',
        'contact_id',
        'address_id',
        'appointment_id',
        'recovery_action',
        'block_reason',
        'attempts_remaining',
        'transaction_id',
        'purpose',
        'provider_order_id',
        'duration_ms',
        'failure_stage',
        'exception_category',
        'request_dispatched',
        'response_received',
        'http_status',
        'reason',
        'stage',
        'expires_at',
        'operation',
        'amount',
        'currency',
        'call_number',
        'processor_transaction_id',
        'attempt_id',
        'reused_token_id',
        'external_tokenization_attempted',
    ];

    public const MONETARY_METADATA_ALLOWLIST = [
        'operation',
        'attempt_id',
        'session_id',
        'provider_order_id',
        'processor_transaction_id',
        'amount',
        'currency',
        'http_status',
        'duration_ms',
        'call_number',
        'detected_by',
        'reason',
        'stage',
        'failure_stage',
        'exception_category',
        'request_dispatched',
        'response_received',
        'reused_token_id',
        'external_tokenization_attempted',
    ];

    private const EXTERNAL_COUNTERS = [
        'payments3DS_GetLink' => 'provider_link_call_count',
        'payments3DS_GetStatus' => 'status_poll_call_count',
        'getTokenize' => 'tokenization_call_count',
    ];

    public function record(
        PaymentAuthenticationAttempt $attempt,
        PaymentAuthenticationAttemptEventType $eventType,
        array $attributes = []
    ): ?PaymentAuthenticationAttemptEvent {
        return DB::transaction(function () use ($attempt, $eventType, $attributes) {
            $lockedAttempt = PaymentAuthenticationAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->firstOrFail();

            $externalOperation = $attributes['external_operation'] ?? null;
            $externalCallNumber = null;

            if ($externalOperation) {
                $externalCallNumber = $this->incrementExternalCounter($lockedAttempt, (string) $externalOperation);
            }

            $event = $this->createEvent($lockedAttempt, $eventType, array_merge($attributes, [
                'external_call_number' => $attributes['external_call_number'] ?? $externalCallNumber,
            ]));

            if (! $event) {
                return null;
            }

            Log::info('[3DS Auth] Event recorded', [
                'attempt_id' => $lockedAttempt->id,
                'support_reference' => $lockedAttempt->support_reference,
                'event_type' => $eventType->value,
                'status_to' => $event->status_to,
                'result_category' => $event->result_category,
            ]);

            return $event;
        });
    }

    public function transition(
        PaymentAuthenticationAttempt $attempt,
        PaymentAuthenticationAttemptStatus $statusTo,
        PaymentAuthenticationAttemptEventType $eventType,
        array $attributes = []
    ): ?PaymentAuthenticationAttemptEvent {
        $current = $attempt->fresh();
        $currentStatus = PaymentAuthenticationAttemptStatus::tryFrom($current->status);

        if ($currentStatus && $currentStatus !== $statusTo && ! $currentStatus->canTransitionTo($statusTo)) {
            $this->record($current, PaymentAuthenticationAttemptEventType::StateConflictDetected, [
                'source' => 'system',
                'status_from' => $current->status,
                'status_to' => $statusTo->value,
                'result_category' => EfevooPay3dsResultClassifier::CATEGORY_UNKNOWN,
                'failure_origin' => EfevooPay3dsResultClassifier::ORIGIN_FAMEDIC,
                'failure_certainty' => EfevooPay3dsResultClassifier::CERTAINTY_CONFIRMED,
                'dedupe_key' => 'state_conflict:'.$current->status.':'.$statusTo->value.':'.$eventType->value,
            ]);

            throw new \DomainException("Invalid authentication attempt transition {$current->status} -> {$statusTo->value}");
        }

        return DB::transaction(function () use ($attempt, $statusTo, $eventType, $attributes) {
            $lockedAttempt = PaymentAuthenticationAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->firstOrFail();

            $statusFromValue = $lockedAttempt->status;

            $externalOperation = $attributes['external_operation'] ?? null;
            $externalCallNumber = null;

            if ($externalOperation) {
                $externalCallNumber = $this->incrementExternalCounter($lockedAttempt, (string) $externalOperation);
            }

            $updates = [
                'status' => $statusTo->value,
                'failure_category' => $attributes['result_category'] ?? $lockedAttempt->failure_category,
                'failure_origin' => $attributes['failure_origin'] ?? $lockedAttempt->failure_origin,
                'failure_certainty' => $attributes['failure_certainty'] ?? $lockedAttempt->failure_certainty,
                'provider_code' => $attributes['provider_code'] ?? $lockedAttempt->provider_code,
                'provider_message' => $attributes['provider_message'] ?? $lockedAttempt->provider_message,
                'updated_at' => now(),
            ];

            if (in_array($statusTo->value, PaymentAuthenticationAttemptStatus::terminalValues(), true)) {
                $updates['finished_at'] = $attributes['finished_at'] ?? now();
            }

            $lockedAttempt->update($updates);

            return $this->createEvent($lockedAttempt, $eventType, array_merge($attributes, [
                'status_from' => $statusFromValue,
                'status_to' => $statusTo->value,
                'external_call_number' => $attributes['external_call_number'] ?? $externalCallNumber,
            ]));
        });
    }

    private function createEvent(
        PaymentAuthenticationAttempt $attempt,
        PaymentAuthenticationAttemptEventType $eventType,
        array $attributes
    ): ?PaymentAuthenticationAttemptEvent {
        $dedupeKey = $attributes['dedupe_key'] ?? null;

        try {
            return PaymentAuthenticationAttemptEvent::query()->create([
                'event_uuid' => (string) Str::uuid(),
                'payment_authentication_attempt_id' => $attempt->id,
                'event_type' => $eventType->value,
                'source' => $this->source($attributes['source'] ?? 'backend'),
                'status_from' => $this->safeString($attributes['status_from'] ?? null, 80),
                'status_to' => $this->safeString($attributes['status_to'] ?? null, 80),
                'result_category' => $this->safeString($attributes['result_category'] ?? null, 80),
                'failure_origin' => $this->safeString($attributes['failure_origin'] ?? null, 80),
                'failure_certainty' => $this->safeString($attributes['failure_certainty'] ?? null, 40),
                'provider_status' => $this->safeString($attributes['provider_status'] ?? null, 120),
                'provider_code' => $this->safeString($attributes['provider_code'] ?? null, 80),
                'provider_message' => $this->safeMessage($attributes['provider_message'] ?? null),
                'external_operation' => $this->safeString($attributes['external_operation'] ?? null, 100),
                'external_call_number' => $attributes['external_call_number'] ?? null,
                'http_status' => $attributes['http_status'] ?? null,
                'duration_ms' => $attributes['duration_ms'] ?? null,
                'dedupe_key' => $this->safeString($dedupeKey, 160),
                'metadata' => $this->metadata($attributes['metadata'] ?? []),
                'occurred_at' => $attributes['occurred_at'] ?? now(),
                'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            if ($dedupeKey && (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'UNIQUE'))) {
                $existing = PaymentAuthenticationAttemptEvent::query()
                    ->where('payment_authentication_attempt_id', $attempt->id)
                    ->where('dedupe_key', $dedupeKey)
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            throw $e;
        }
    }

    private function incrementExternalCounter(PaymentAuthenticationAttempt $attempt, string $operation): int
    {
        $counter = self::EXTERNAL_COUNTERS[$operation] ?? null;

        $updates = ['external_call_count' => DB::raw('external_call_count + 1')];

        if ($counter) {
            $updates[$counter] = DB::raw($counter.' + 1');
        }

        PaymentAuthenticationAttempt::query()
            ->whereKey($attempt->id)
            ->update($updates);

        $attempt->refresh();

        return match ($operation) {
            'payments3DS_GetLink' => $attempt->provider_link_call_count,
            'payments3DS_GetStatus' => $attempt->status_poll_call_count,
            'getTokenize' => $attempt->tokenization_call_count,
            default => $attempt->external_call_count,
        };
    }

    private function metadata(mixed $metadata): ?array
    {
        if (! is_array($metadata)) {
            return null;
        }

        $safe = [];

        foreach (self::METADATA_ALLOWLIST as $key) {
            if (! array_key_exists($key, $metadata)) {
                continue;
            }

            $value = $metadata[$key];

            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $safe[$key] = $value;
            } elseif (is_string($value)) {
                $safe[$key] = $this->safeString($value, 160);
            }
        }

        return $safe === [] ? null : $safe;
    }

    private function source(string $source): string
    {
        return in_array($source, self::SOURCES, true) ? $source : 'system';
    }

    private function safeString(mixed $value, int $limit): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $safe = preg_replace('/\b\d{13,19}\b/', '[redacted_pan]', (string) $value);
        $safe = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer [redacted]', $safe ?? '');

        return Str::limit($safe ?? '', $limit, '');
    }

    private function safeMessage(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Str::limit(EfevooPayLogSanitizer::providerMessage((string) $value), 500, '');
    }
}
