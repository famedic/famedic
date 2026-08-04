<?php

namespace App\Services\Api\V1\Audit;

use App\Models\Api\V1\ApiV1AuditEvent;
use App\Support\Api\V1\AkubicaCorrelationId;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Synchronous fail-soft writer for API v1 audit events.
 *
 * write() — business-safe: never throws; logs akubica_audit_write_failed
 *           with a minimal allowlist (event_name, correlation_id, exception_class).
 * persistOrFail() — internal/tests: surfaces persistence failures.
 *
 * Does not begin, commit, or roll back caller transactions. If the current
 * DB connection is already inside a failed/aborted transaction, the insert
 * may fail; write() swallows that and logs. Prefer emitting audit rows after
 * the business transaction commits when atomicity with side effects matters.
 * Queue is intentionally not used in this phase.
 */
final class AuditEventWriter
{
    public function __construct(
        private readonly AuditMetadataNormalizer $normalizer,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('api_v1.audit.enabled', false);
    }

    /**
     * Fail-soft entry point for future business instrumentation.
     *
     * @param  array{
     *     event_name: string,
     *     outcome: string,
     *     actor?: AuditActor|null,
     *     context?: ApiV1AuditContext|null,
     *     resource_type?: string|null,
     *     resource_key?: string|null,
     *     http_status?: int|null,
     *     error_code?: string|null,
     *     retryable?: bool|null,
     *     metadata?: array<string, mixed>|null,
     *     occurred_at?: DateTimeInterface|string|null,
     *     method?: string|null,
     *     route_name?: string|null,
     *     correlation_id?: string|null,
     *     related_correlation_id?: string|null,
     *     idempotency_record_id?: int|null,
     *     idempotency_effect?: string|null,
     *     ip_hash?: string|null,
     *     user_agent_hash?: string|null,
     *     mark_terminal?: bool
     * }  $input
     */
    public function write(array $input): ?ApiV1AuditEvent
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            return $this->persistOrFail($input);
        } catch (Throwable $e) {
            $this->logWriteFailure(
                eventName: is_string($input['event_name'] ?? null) ? $input['event_name'] : 'unknown',
                correlationId: $this->safeCorrelationId($input),
                exception: $e,
            );

            return null;
        }
    }

    /**
     * Persist or throw. Used by tests and internal callers that must observe failure.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws Throwable
     */
    public function persistOrFail(array $input): ApiV1AuditEvent
    {
        $eventName = $input['event_name'] ?? null;
        if (! is_string($eventName) || $eventName === '') {
            throw new InvalidArgumentException('audit event_name is required.');
        }

        if (strlen($eventName) > 96) {
            throw new InvalidArgumentException('audit event_name exceeds 96 characters.');
        }

        $outcome = $input['outcome'] ?? null;
        if (! is_string($outcome) || $outcome === '') {
            throw new InvalidArgumentException('audit outcome is required.');
        }

        $context = $input['context'] ?? null;
        if (! $context instanceof ApiV1AuditContext) {
            $context = ApiV1AuditContext::currentOrCreate();
        }

        $actor = $input['actor'] ?? $context->actor();
        if (! $actor instanceof AuditActor) {
            throw new InvalidArgumentException('audit actor is required.');
        }

        $correlationId = $input['correlation_id']
            ?? $context->correlationId()
            ?? AkubicaCorrelationId::currentOrGenerate();

        $method = $input['method'] ?? $context->method() ?? 'SYSTEM';
        $routeName = $input['route_name'] ?? $context->routeName();

        $metadata = $this->normalizer->normalize(
            $eventName,
            is_array($input['metadata'] ?? null) ? $input['metadata'] : null
        );

        $occurredAt = $input['occurred_at'] ?? now();

        $attributes = array_merge(
            $actor->toWriterAttributes(),
            [
                'event_name' => $eventName,
                'occurred_at' => $occurredAt,
                'correlation_id' => mb_substr((string) $correlationId, 0, 128),
                'related_correlation_id' => $this->nullableString(
                    $input['related_correlation_id'] ?? $context->relatedCorrelationId(),
                    128
                ),
                'resource_type' => $this->nullableString($input['resource_type'] ?? null, 64),
                'resource_key' => $this->nullableString($input['resource_key'] ?? null, 128),
                'route_name' => $this->nullableString($routeName, 128),
                'method' => strtoupper(mb_substr((string) $method, 0, 10)),
                'outcome' => mb_substr($outcome, 0, 32),
                'http_status' => isset($input['http_status']) ? (int) $input['http_status'] : null,
                'error_code' => $this->nullableString($input['error_code'] ?? null, 64),
                'retryable' => array_key_exists('retryable', $input)
                    ? (is_bool($input['retryable']) ? $input['retryable'] : null)
                    : null,
                'idempotency_record_id' => $input['idempotency_record_id']
                    ?? $context->idempotencyRecordId(),
                'idempotency_effect' => $this->nullableString(
                    $input['idempotency_effect'] ?? $context->idempotencyEffect(),
                    24
                ),
                'ip_hash' => $this->nullableString($input['ip_hash'] ?? null, 64),
                'user_agent_hash' => $this->nullableString($input['user_agent_hash'] ?? null, 64),
                'metadata' => $metadata,
                'created_at' => now(),
            ]
        );

        $event = new ApiV1AuditEvent;
        $event->fillWriterAttributes($attributes);
        $event->save();

        if (($input['mark_terminal'] ?? false) === true) {
            $context->markTerminalEventEmitted();
        }

        return $event;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function safeCorrelationId(array $input): ?string
    {
        if (is_string($input['correlation_id'] ?? null) && $input['correlation_id'] !== '') {
            return mb_substr($input['correlation_id'], 0, 128);
        }

        $context = $input['context'] ?? null;
        if ($context instanceof ApiV1AuditContext && is_string($context->correlationId())) {
            return $context->correlationId();
        }

        $current = ApiV1AuditContext::current();

        return $current?->correlationId();
    }

    private function logWriteFailure(string $eventName, ?string $correlationId, Throwable $exception): void
    {
        // Minimal allowlist only — no message, SQL, bindings, payload, or metadata.
        Log::error('akubica_audit_write_failed', [
            'event_name' => mb_substr($eventName, 0, 96),
            'correlation_id' => $correlationId,
            'exception_class' => $exception::class,
        ]);
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }
}
