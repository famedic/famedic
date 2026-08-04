<?php

namespace App\Services\Audit\Business;

use App\Models\Audit\BusinessAuditEvent;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Synchronous fail-soft writer for business audit events (Block 6A).
 *
 * write() — business-safe: never throws; logs business_audit_write_failed
 *           with a minimal allowlist (event_name, outcome, correlation_id, exception_class).
 * persistOrFail() — internal/tests: surfaces persistence failures.
 *
 * Does not begin, commit, or roll back caller transactions. Does not use
 * queue/jobs. Does not call report(). Does not depend on request() global.
 * Independent from API V1 AuditEventWriter / API_V1_AUDIT_ENABLED.
 */
final class BusinessAuditEventWriter
{
    public function __construct(
        private readonly BusinessAuditMetadataNormalizer $normalizer,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('business_audit.enabled', false);
    }

    /**
     * Fail-soft entry point.
     *
     * @param  array{
     *     event_name: string,
     *     outcome: string,
     *     context: BusinessAuditContext,
     *     resource_type?: string|null,
     *     resource_key?: string|null,
     *     error_code?: string|null,
     *     retryable?: bool|null,
     *     metadata?: array<string, mixed>|null,
     *     occurred_at?: DateTimeInterface|string|null,
     *     correlation_id?: string|null,
     *     public_id?: string|null
     * }  $input
     */
    public function write(array $input): ?BusinessAuditEvent
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            return $this->persistOrFail($input);
        } catch (Throwable $e) {
            $this->logWriteFailure(
                eventName: is_string($input['event_name'] ?? null) ? $input['event_name'] : 'unknown',
                outcome: is_string($input['outcome'] ?? null) ? $input['outcome'] : null,
                correlationId: $this->safeCorrelationIdForLog($input),
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
    public function persistOrFail(array $input): BusinessAuditEvent
    {
        $eventName = $input['event_name'] ?? null;
        if (! is_string($eventName) || $eventName === '') {
            throw new InvalidArgumentException('business audit event_name is required.');
        }

        if (strlen($eventName) > 96) {
            throw new InvalidArgumentException('business audit event_name exceeds 96 characters.');
        }

        if (str_starts_with($eventName, 'payment.')) {
            throw new InvalidArgumentException('business audit rejects payment.* event names.');
        }

        if (! BusinessAuditEventDefinitions::isKnownEvent($eventName)) {
            throw new InvalidArgumentException('business audit event_name is not registered.');
        }

        $outcome = $input['outcome'] ?? null;
        if (! is_string($outcome) || ! BusinessAuditOutcome::isValid($outcome)) {
            throw new InvalidArgumentException('business audit outcome is not allowlisted.');
        }

        if (! in_array($outcome, BusinessAuditEventDefinitions::allowedOutcomes($eventName), true)) {
            throw new InvalidArgumentException('business audit outcome is not allowed for this event.');
        }

        $context = $input['context'] ?? null;
        if (! $context instanceof BusinessAuditContext) {
            throw new InvalidArgumentException('business audit context is required.');
        }

        $actor = $context->actor();
        if (! in_array($actor->type, BusinessAuditEventDefinitions::allowedActorTypes($eventName), true)) {
            throw new InvalidArgumentException('business audit actor_type is not allowed for this event.');
        }

        $channel = $context->channel();
        if (! in_array($channel, BusinessAuditEventDefinitions::allowedChannels($eventName), true)) {
            throw new InvalidArgumentException('business audit channel is not allowed for this event.');
        }

        $subject = $context->subject();
        $subjectType = null;
        $subjectKey = null;
        if ($subject instanceof BusinessAuditSubject) {
            $allowedSubjects = BusinessAuditEventDefinitions::allowedSubjectTypes($eventName);
            if (is_array($allowedSubjects) && ! in_array($subject->type, $allowedSubjects, true)) {
                throw new InvalidArgumentException('business audit subject_type is not allowed for this event.');
            }
            $subjectType = $subject->type;
            $subjectKey = $subject->key;
        }

        $resourceType = $this->nullableOpaqueString($input['resource_type'] ?? null, 64);
        $resourceKey = $this->nullableOpaqueString($input['resource_key'] ?? null, 128);
        if ($resourceType !== null) {
            $allowedResources = BusinessAuditEventDefinitions::allowedResourceTypes($eventName);
            if (is_array($allowedResources) && $allowedResources !== []
                && ! in_array($resourceType, $allowedResources, true)
            ) {
                throw new InvalidArgumentException('business audit resource_type is not allowed for this event.');
            }
        }

        $correlationId = BusinessAuditCorrelationId::resolve(
            is_string($input['correlation_id'] ?? null)
                ? $input['correlation_id']
                : $context->correlationId()
        );

        $metadata = $this->normalizer->normalize(
            $eventName,
            is_array($input['metadata'] ?? null) ? $input['metadata'] : null
        );

        $occurredAt = $input['occurred_at'] ?? $context->occurredAt() ?? now();

        $retryable = null;
        if (array_key_exists('retryable', $input)) {
            $retryable = is_bool($input['retryable']) ? $input['retryable'] : null;
        }

        $publicId = $input['public_id'] ?? null;
        if (! is_string($publicId) || $publicId === '' || ! Str::isUuid($publicId)) {
            $publicId = (string) Str::uuid();
        }

        $attributes = array_merge(
            $actor->toWriterAttributes(),
            [
                'public_id' => $publicId,
                'occurred_at' => $occurredAt,
                'event_name' => $eventName,
                'outcome' => $outcome,
                'channel' => $channel,
                'subject_type' => $subjectType,
                'subject_key' => $subjectKey,
                'resource_type' => $resourceType,
                'resource_key' => $resourceKey,
                'correlation_id' => $correlationId,
                'error_code' => $this->nullableErrorCode($input['error_code'] ?? null),
                'retryable' => $retryable,
                'metadata' => $metadata,
                'created_at' => now(),
            ]
        );

        $event = new BusinessAuditEvent;
        $event->fillWriterAttributes($attributes);
        $event->save();

        return $event;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function safeCorrelationIdForLog(array $input): ?string
    {
        if (is_string($input['correlation_id'] ?? null)
            && BusinessAuditCorrelationId::isValid($input['correlation_id'])
        ) {
            return mb_substr($input['correlation_id'], 0, 128);
        }

        $context = $input['context'] ?? null;
        if ($context instanceof BusinessAuditContext
            && is_string($context->correlationId())
            && BusinessAuditCorrelationId::isValid($context->correlationId())
        ) {
            return $context->correlationId();
        }

        return null;
    }

    private function logWriteFailure(
        string $eventName,
        ?string $outcome,
        ?string $correlationId,
        Throwable $exception,
    ): void {
        // Minimal allowlist only — no message, SQL, bindings, payload, or metadata.
        Log::error('business_audit_write_failed', [
            'event_name' => mb_substr($eventName, 0, 96),
            'outcome' => is_string($outcome) ? mb_substr($outcome, 0, 32) : null,
            'correlation_id' => $correlationId,
            'exception_class' => $exception::class,
        ]);
    }

    private function nullableOpaqueString(mixed $value, int $max): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || strlen($trimmed) > $max) {
            return null;
        }

        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $trimmed)) {
            return null;
        }

        return mb_substr($trimmed, 0, $max);
    }

    private function nullableErrorCode(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || strlen($trimmed) > 64) {
            return null;
        }

        // Stable opaque codes: UPPER_SNAKE or similar — reject free-form sentences.
        if (! preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $trimmed)) {
            return null;
        }

        return $trimmed;
    }
}
