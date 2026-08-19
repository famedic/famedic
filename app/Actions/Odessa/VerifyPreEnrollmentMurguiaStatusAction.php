<?php

namespace App\Actions\Odessa;

use App\Actions\Customers\GenerateUniqueMedicalAttentionIdAction;
use App\Actions\MedicalAttention\CheckStatusAction;
use App\Models\OdessaPreEnrollment;
use App\Models\OdessaPreEnrollmentAudit;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class VerifyPreEnrollmentMurguiaStatusAction
{
    public const EVENT_READBACK_STARTED = 'MURGUIA_READBACK_STARTED';
    public const EVENT_READBACK_ACTIVE = 'MURGUIA_READBACK_ACTIVE';
    public const EVENT_READBACK_INACTIVE = 'MURGUIA_READBACK_INACTIVE';
    public const EVENT_READBACK_NOT_FOUND = 'MURGUIA_READBACK_NOT_FOUND';
    public const EVENT_READBACK_UNKNOWN = 'MURGUIA_READBACK_UNKNOWN';
    public const EVENT_READBACK_FAILED = 'MURGUIA_READBACK_FAILED';
    public const EVENT_STALE_OPERATION_RESULT_IGNORED = 'STALE_OPERATION_RESULT_IGNORED';

    private const FINAL_EVENTS = [
        self::EVENT_READBACK_ACTIVE,
        self::EVENT_READBACK_INACTIVE,
        self::EVENT_READBACK_NOT_FOUND,
        self::EVENT_READBACK_UNKNOWN,
        self::EVENT_READBACK_FAILED,
    ];

    public function __construct(
        private CheckStatusAction $checkStatusAction,
        private GenerateUniqueMedicalAttentionIdAction $identifierAction,
    ) {}

    public function execute(OdessaPreEnrollment $preEnrollment, User $actor): array
    {
        if (! config('famedic.odessa_pre_enrollments.murguia_enabled', false)) {
            return ['ok' => false, 'message' => 'La verificación Murguía está deshabilitada por configuración.', 'code' => 'flag_off'];
        }

        $prepared = $this->prepare($preEnrollment, $actor);
        if (! ($prepared['ok'] ?? false)) {
            return $prepared;
        }

        try {
            $response = ($this->checkStatusAction)((string) $prepared['identifier']);
        } catch (ConnectionException) {
            return $this->applyReadbackResult($preEnrollment, $actor, null, self::EVENT_READBACK_FAILED, $prepared['operation_token'], $prepared['correlation_id']);
        } catch (Throwable) {
            return $this->applyReadbackResult($preEnrollment, $actor, null, self::EVENT_READBACK_FAILED, $prepared['operation_token'], $prepared['correlation_id']);
        }

        return $this->applyReadbackResult(
            $preEnrollment,
            $actor,
            $response,
            $this->classifyReadback($response),
            $prepared['operation_token'],
            $prepared['correlation_id'],
        );
    }

    public function applyReadbackResult(
        OdessaPreEnrollment $preEnrollment,
        User $actor,
        ?Response $response,
        string $eventCode,
        string $operationToken,
        string $correlationId,
    ): array {
        $eventCode = in_array($eventCode, self::FINAL_EVENTS, true) ? $eventCode : self::EVENT_READBACK_FAILED;
        $httpStatus = $response?->status();

        return DB::transaction(function () use ($preEnrollment, $actor, $eventCode, $httpStatus, $operationToken, $correlationId) {
            $locked = OdessaPreEnrollment::query()
                ->whereKey($preEnrollment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $before = $this->auditState($locked);
            if ($locked->murguia_operation_token !== $operationToken || $locked->murguia_correlation_id !== $correlationId) {
                $this->audit($locked, $actor, self::EVENT_STALE_OPERATION_RESULT_IGNORED, $before, $before, $httpStatus);

                return [
                    'ok' => false,
                    'status' => $locked->murguia_status,
                    'code' => self::EVENT_STALE_OPERATION_RESULT_IGNORED,
                    'message' => 'Se ignoró una respuesta Murguía anterior a la operación actual.',
                ];
            }

            $status = match ($eventCode) {
                self::EVENT_READBACK_ACTIVE => OdessaPreEnrollment::MURGUIA_ACTIVE,
                self::EVENT_READBACK_INACTIVE => OdessaPreEnrollment::MURGUIA_INACTIVE,
                self::EVENT_READBACK_NOT_FOUND => OdessaPreEnrollment::MURGUIA_FAILED,
                default => OdessaPreEnrollment::MURGUIA_PENDING,
            };

            $locked->forceFill([
                'murguia_status' => $status,
                'murguia_synced_at' => $status === OdessaPreEnrollment::MURGUIA_ACTIVE ? now() : $locked->murguia_synced_at,
                'murguia_pending_since' => $status === OdessaPreEnrollment::MURGUIA_PENDING ? ($locked->murguia_pending_since ?: now()) : null,
                'murguia_checked_at' => now(),
                'murguia_last_http_status' => $httpStatus,
                'murguia_last_event_code' => $eventCode,
            ])->save();

            $this->audit($locked, $actor, $eventCode, $before, $this->auditState($locked), $httpStatus);

            return [
                'ok' => ! in_array($eventCode, [self::EVENT_READBACK_FAILED, self::EVENT_READBACK_UNKNOWN], true),
                'status' => $locked->murguia_status,
                'code' => $eventCode,
                'message' => $this->messageFor($eventCode),
            ];
        });
    }

    private function prepare(OdessaPreEnrollment $preEnrollment, User $actor): array
    {
        return DB::transaction(function () use ($preEnrollment, $actor) {
            $locked = OdessaPreEnrollment::query()
                ->whereKey($preEnrollment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $validation = $this->validatePreEnrollment($locked, requireNotActive: false);
            if ($validation !== null) {
                return ['ok' => false, 'message' => $validation, 'code' => 'not_allowed'];
            }

            $before = $this->auditState($locked);
            $locked->forceFill([
                'murguia_correlation_id' => (string) Str::uuid(),
                'murguia_operation_token' => (string) Str::uuid(),
                'murguia_last_event_code' => self::EVENT_READBACK_STARTED,
            ])->save();

            $this->audit($locked, $actor, self::EVENT_READBACK_STARTED, $before, $this->auditState($locked), null);

            return [
                'ok' => true,
                'identifier' => (string) $locked->medical_attention_identifier,
                'operation_token' => $locked->murguia_operation_token,
                'correlation_id' => $locked->murguia_correlation_id,
            ];
        });
    }

    private function validatePreEnrollment(OdessaPreEnrollment $preEnrollment, bool $requireNotActive): ?string
    {
        if ($preEnrollment->status !== OdessaPreEnrollment::STATUS_READY) {
            return 'Sólo se puede operar una preafiliación READY.';
        }
        if ($preEnrollment->membership_type !== 'institutional') {
            return 'Sólo se puede operar una membresía institucional.';
        }
        if (! $this->identifierAction->isValidIdentifier($preEnrollment->medical_attention_identifier)) {
            return 'La preafiliación no tiene identificador reservado.';
        }
        if ($requireNotActive && $preEnrollment->murguia_status === OdessaPreEnrollment::MURGUIA_ACTIVE) {
            return 'La preafiliación ya está activa en Murguía.';
        }

        return null;
    }

    private function classifyReadback(Response $response): string
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($this->hasExplicitNotFoundCode($body)) {
            return self::EVENT_READBACK_NOT_FOUND;
        }

        if (! $response->successful()) {
            return in_array($response->status(), [404, 410], true)
                ? self::EVENT_READBACK_UNKNOWN
                : self::EVENT_READBACK_FAILED;
        }

        if (($body['success'] ?? null) !== true) {
            return self::EVENT_READBACK_UNKNOWN;
        }

        $status = $this->normalizeStatus($body['estatus'] ?? $body['status'] ?? $body['estado'] ?? null);
        if (in_array($status, ['inactivo', 'inactive'], true)) {
            return self::EVENT_READBACK_INACTIVE;
        }
        if (in_array($status, ['activo', 'active'], true)) {
            return self::EVENT_READBACK_ACTIVE;
        }

        return self::EVENT_READBACK_UNKNOWN;
    }

    private function hasExplicitNotFoundCode(array $body): bool
    {
        $code = $this->normalizeTechnicalCode($body['result_code'] ?? $body['error_code'] ?? null);
        if ($code === null) {
            return false;
        }

        return in_array($code, $this->notFoundCodes(), true);
    }

    private function normalizeStatus(mixed $value): string
    {
        return strtolower(Str::ascii(trim((string) $value)));
    }

    private function normalizeTechnicalCode(mixed $value): ?string
    {
        $code = strtoupper(Str::ascii(trim((string) $value)));

        return preg_match('/^[A-Z0-9_:-]{2,64}$/', $code) ? $code : null;
    }

    private function notFoundCodes(): array
    {
        return collect(config('famedic.odessa_pre_enrollments.murguia_not_found_codes', []))
            ->map(fn (mixed $code) => $this->normalizeTechnicalCode($code))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function audit(OdessaPreEnrollment $preEnrollment, User $actor, string $eventCode, array $before, array $after, ?int $httpStatus): void
    {
        OdessaPreEnrollmentAudit::create([
            'odessa_pre_enrollment_id' => $preEnrollment->id,
            'performed_by' => $actor->id,
            'action_type' => $eventCode,
            'before_json' => $before,
            'after_json' => array_filter([
                ...$after,
                'http_status' => $httpStatus,
                'event_code' => $eventCode,
            ], fn ($value) => $value !== null),
            'reason' => null,
            'performed_at' => now(),
        ]);
    }

    private function auditState(OdessaPreEnrollment $preEnrollment): array
    {
        return [
            'pre_enrollment_uuid' => $preEnrollment->uuid,
            'has_medical_attention_identifier' => filled($preEnrollment->medical_attention_identifier),
            'status' => $preEnrollment->status,
            'murguia_status' => $preEnrollment->murguia_status,
            'murguia_attempts' => (int) ($preEnrollment->murguia_attempts ?? 0),
            'murguia_last_event_code' => $preEnrollment->murguia_last_event_code,
        ];
    }

    private function messageFor(string $eventCode): string
    {
        return match ($eventCode) {
            self::EVENT_READBACK_ACTIVE => 'Murguía reporta la preafiliación activa.',
            self::EVENT_READBACK_INACTIVE => 'Murguía reporta la preafiliación inactiva.',
            self::EVENT_READBACK_NOT_FOUND => 'Murguía no encontró un alta activa; se puede reintentar de forma controlada.',
            self::EVENT_READBACK_UNKNOWN => 'Murguía devolvió un estado no concluyente; se conserva operación pendiente.',
            default => 'No se pudo confirmar el estado en Murguía; se conserva estado pendiente.',
        };
    }
}
