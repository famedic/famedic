<?php

namespace App\Services\Api\V1\Audit;

use App\Support\Api\V1\ApiErrorRetryability;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * Typed audit emitter for API V1 laboratory appointments (Block 5).
 *
 * API V1 has no dedicated concierge/callback CRUD. POST laboratory-appointments
 * persists a pending LaboratoryAppointment plus a 1h callback preference window.
 * This recorder asserts only that request/cancel fact — never that a call occurred,
 * a slot was confirmed at a branch, or a notification was delivered.
 */
final class AppointmentConciergeAuditRecorder
{
    public const RESOURCE_LABORATORY_APPOINTMENT = 'laboratory_appointment';

    public const SCHEDULING_MODE_CALLBACK_WINDOW = 'callback_window';

    public const REQUEST_CHANNEL_AKUBICA_API = 'akubica_api';

    public const REQUESTED_WINDOW_ONE_HOUR = 'one_hour';

    public const STATE_PENDING = 'pending';

    public const STATE_CONFIRMED = 'confirmed';

    public const STATE_COMPLETED = 'completed';

    public const STATE_CANCELLED = 'cancelled';

    /** @var list<string> */
    private const CONTROLLED_STATES = [
        self::STATE_PENDING,
        self::STATE_CONFIRMED,
        self::STATE_COMPLETED,
        self::STATE_CANCELLED,
    ];

    public function __construct(
        private readonly AuditEventWriter $writer,
        private readonly AuditActorResolver $actors,
    ) {}

    public function enabled(): bool
    {
        return $this->writer->enabled();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordAppointmentRequested(
        Request $request,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?string $resourceKey = null,
        ?string $laboratoryBrand = null,
        ?int $appointmentRowId = null,
        ?string $appointmentState = null,
        ?CarbonInterface $scheduledAt = null,
        ?bool $checkoutDraftAdvanced = null,
        array $metadata = [],
        bool $markTerminal = true,
    ): void {
        $dateMeta = $this->safeRequestedDateMeta($scheduledAt);

        $this->emit(
            eventName: AuditEventDefinitions::EVENT_APPOINTMENTS_REQUESTED,
            outcome: $outcome,
            request: $request,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            resourceType: $resourceKey !== null ? self::RESOURCE_LABORATORY_APPOINTMENT : null,
            resourceKey: $resourceKey,
            metadata: array_merge([
                'laboratory_brand' => $laboratoryBrand,
                'appointment_row_id' => $appointmentRowId,
                'appointment_state' => $this->controlledState($appointmentState),
                'scheduling_mode' => $outcome === AuditOutcome::SUCCEEDED
                    ? self::SCHEDULING_MODE_CALLBACK_WINDOW
                    : null,
                'request_channel' => self::REQUEST_CHANNEL_AKUBICA_API,
                'requested_date' => $dateMeta['requested_date'],
                'requested_window' => $outcome === AuditOutcome::SUCCEEDED
                    ? self::REQUESTED_WINDOW_ONE_HOUR
                    : null,
                'timezone' => $dateMeta['timezone'],
                'checkout_draft_advanced' => $checkoutDraftAdvanced,
            ], $metadata),
            markTerminal: $markTerminal,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordAppointmentCancelled(
        Request $request,
        string $outcome,
        int $httpStatus,
        ?string $errorCode = null,
        ?string $resourceKey = null,
        ?string $laboratoryBrand = null,
        ?int $appointmentRowId = null,
        ?string $previousState = null,
        array $metadata = [],
        bool $markTerminal = true,
    ): void {
        $this->emit(
            eventName: AuditEventDefinitions::EVENT_APPOINTMENTS_CANCELLED,
            outcome: $outcome,
            request: $request,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            resourceType: $resourceKey !== null ? self::RESOURCE_LABORATORY_APPOINTMENT : null,
            resourceKey: $resourceKey,
            metadata: array_merge([
                'laboratory_brand' => $laboratoryBrand,
                'appointment_row_id' => $appointmentRowId,
                'previous_state' => $this->controlledState($previousState),
                'resulting_state' => $outcome === AuditOutcome::SUCCEEDED
                    ? self::STATE_CANCELLED
                    : null,
                'request_channel' => self::REQUEST_CHANNEL_AKUBICA_API,
            ], $metadata),
            markTerminal: $markTerminal,
        );
    }

    /**
     * @return array{outcome: string, http_status: int, error_code: string|null, retryable: bool|null}
     */
    public function classifyErrorResponse(JsonResponse $response): array
    {
        $status = $response->getStatusCode();
        $payload = $response->getData(true);
        $errorCode = is_array($payload) && is_array($payload['error'] ?? null)
            ? (is_string($payload['error']['code'] ?? null) ? $payload['error']['code'] : null)
            : null;

        $retryable = $errorCode !== null
            ? ApiErrorRetryability::isRetryable($errorCode, $status)
            : null;

        $outcome = match (true) {
            $status >= 500 => AuditOutcome::FAILED,
            $status === 429 => AuditOutcome::REJECTED,
            $status >= 400 => AuditOutcome::REJECTED,
            default => AuditOutcome::FAILED,
        };

        if (in_array($errorCode, ['INTERNAL_ERROR', 'FEATURE_DISABLED'], true)) {
            $outcome = AuditOutcome::FAILED;
        }

        return [
            'outcome' => $outcome,
            'http_status' => $status,
            'error_code' => $errorCode,
            'retryable' => $retryable,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function emit(
        string $eventName,
        string $outcome,
        Request $request,
        int $httpStatus,
        ?string $errorCode,
        array $metadata,
        bool $markTerminal,
        ?string $resourceType = null,
        ?string $resourceKey = null,
    ): void {
        if (! $this->enabled()) {
            return;
        }

        $actor = $this->safeAuthenticatedActor($request);
        if ($actor === null) {
            return;
        }

        $context = ApiV1AuditContext::fromRequest($request);
        if ($context->actor() === null) {
            $context->setActor($actor);
        }

        $cleanMeta = [];
        foreach ($metadata as $k => $v) {
            if ($v === null) {
                continue;
            }
            $cleanMeta[$k] = $v;
        }

        $retryable = $errorCode !== null
            ? ApiErrorRetryability::isRetryable($errorCode, $httpStatus)
            : ($httpStatus < 400 ? false : null);

        $this->writer->write([
            'event_name' => $eventName,
            'outcome' => $outcome,
            'actor' => $actor,
            'context' => $context,
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            'retryable' => $retryable,
            'resource_type' => $resourceType,
            'resource_key' => $resourceKey,
            'metadata' => $cleanMeta,
            'ip_hash' => $this->actors->hashIp($request->ip()),
            'user_agent_hash' => $this->actors->hashUserAgent($request->userAgent()),
            'mark_terminal' => $markTerminal,
        ]);
    }

    private function safeAuthenticatedActor(Request $request): ?AuditActor
    {
        try {
            return $this->actors->resolveAuthenticated($request);
        } catch (InvalidArgumentException|Throwable) {
            return null;
        }
    }

    private function controlledState(?string $state): ?string
    {
        if ($state === null) {
            return null;
        }

        return in_array($state, self::CONTROLLED_STATES, true) ? $state : null;
    }

    /**
     * Date-only preference (YYYY-MM-DD) + controlled IANA timezone.
     * Never emits clock time or free-form notes.
     *
     * @return array{requested_date: string|null, timezone: string|null}
     */
    private function safeRequestedDateMeta(?CarbonInterface $scheduledAt): array
    {
        if ($scheduledAt === null) {
            return ['requested_date' => null, 'timezone' => null];
        }

        return [
            'requested_date' => $scheduledAt->toDateString(),
            'timezone' => $this->controlledTimezone((string) config('app.timezone', 'UTC')),
        ];
    }

    /**
     * Accept only valid IANA timezone identifiers (not free-form text).
     */
    private function controlledTimezone(string $tz): ?string
    {
        if ($tz === '' || strlen($tz) > 64 || ! preg_match('/^[A-Za-z0-9_+\-\/]+$/', $tz)) {
            return null;
        }

        try {
            new \DateTimeZone($tz);
        } catch (\Exception) {
            return null;
        }

        return $tz;
    }
}