<?php

namespace App\Actions\Odessa;

use App\Actions\Customers\GenerateUniqueMedicalAttentionIdAction;
use App\Actions\MedicalAttention\AuthorizationAction;
use App\Models\OdessaPreEnrollment;
use App\Models\OdessaPreEnrollmentAudit;
use App\Models\User;
use App\Services\Odessa\PreEnrollment\OdessaPreEnrollmentMurguiaRegistrationPayload;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class RegisterPreEnrollmentWithMurguiaAction
{
    public const EVENT_REGISTER_STARTED = 'MURGUIA_REGISTER_STARTED';
    public const EVENT_REGISTER_ACCEPTED = 'MURGUIA_REGISTER_ACCEPTED';
    public const EVENT_REGISTER_REJECTED = 'MURGUIA_REGISTER_REJECTED';
    public const EVENT_REGISTER_OUTCOME_UNKNOWN = 'MURGUIA_REGISTER_OUTCOME_UNKNOWN';
    public const EVENT_CONTRACT_NOT_CONFIGURED = 'MURGUIA_CONTRACT_NOT_CONFIGURED';
    public const EVENT_MEMBERSHIP_DATES_MISMATCH = 'MURGUIA_MEMBERSHIP_DATES_MISMATCH';
    public const EVENT_STALE_OPERATION_RESULT_IGNORED = 'STALE_OPERATION_RESULT_IGNORED';

    public function __construct(
        private AuthorizationAction $authorizationAction,
        private VerifyPreEnrollmentMurguiaStatusAction $verifyAction,
        private GenerateUniqueMedicalAttentionIdAction $identifierAction,
    ) {}

    public function execute(OdessaPreEnrollment $preEnrollment, User $actor): array
    {
        if (! config('famedic.odessa_pre_enrollments.murguia_enabled', false)) {
            return ['ok' => false, 'message' => 'El alta Murguía está deshabilitada por configuración.', 'code' => 'flag_off'];
        }

        if ($preEnrollment->murguia_status === OdessaPreEnrollment::MURGUIA_PENDING && ! $this->leaseExpired($preEnrollment)) {
            return ['ok' => false, 'message' => 'Hay una operación Murguía en curso.', 'code' => 'operation_in_progress'];
        }

        if ($preEnrollment->murguia_status === OdessaPreEnrollment::MURGUIA_PENDING && $this->leaseExpired($preEnrollment)) {
            $readback = $this->verifyAction->execute($preEnrollment, $actor);
            $preEnrollment = $preEnrollment->fresh();
            if (in_array($preEnrollment->murguia_status, [OdessaPreEnrollment::MURGUIA_ACTIVE, OdessaPreEnrollment::MURGUIA_INACTIVE], true)) {
                return $readback;
            }
            if (($readback['code'] ?? null) !== VerifyPreEnrollmentMurguiaStatusAction::EVENT_READBACK_NOT_FOUND) {
                return $readback;
            }
        }

        if ($preEnrollment->murguia_status === OdessaPreEnrollment::MURGUIA_ACTIVE) {
            return ['ok' => true, 'message' => 'La preafiliación ya está activa en Murguía.', 'code' => 'already_active'];
        }

        $prepared = $this->prepare($preEnrollment, $actor);
        if (! ($prepared['ok'] ?? false)) {
            return $prepared;
        }
        if (! isset($prepared['payload'], $prepared['operation_token'], $prepared['correlation_id'])) {
            return $prepared;
        }

        try {
            $response = $this->sendRegister($prepared['payload']);
        } catch (ConnectionException) {
            return $this->markUnknown($preEnrollment, $actor, null, $prepared['operation_token'], $prepared['correlation_id']);
        } catch (Throwable) {
            return $this->markUnknown($preEnrollment, $actor, null, $prepared['operation_token'], $prepared['correlation_id']);
        }

        if ($this->isAcceptedOrDuplicate($response)) {
            $accepted = $this->markAccepted($preEnrollment, $actor, $response->status(), $prepared['operation_token'], $prepared['correlation_id']);
            if (($accepted['code'] ?? null) === self::EVENT_STALE_OPERATION_RESULT_IGNORED) {
                return $accepted;
            }

            return $this->verifyAction->execute($preEnrollment->fresh(), $actor);
        }

        if ($this->isFunctionalRejection($response)) {
            return $this->markRejected($preEnrollment, $actor, $response->status(), $prepared['operation_token'], $prepared['correlation_id']);
        }

        return $this->markUnknown($preEnrollment, $actor, $response->status(), $prepared['operation_token'], $prepared['correlation_id']);
    }

    private function prepare(OdessaPreEnrollment $preEnrollment, User $actor): array
    {
        return DB::transaction(function () use ($preEnrollment, $actor) {
            $locked = OdessaPreEnrollment::query()
                ->whereKey($preEnrollment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $validation = $this->validatePreEnrollment($locked);
            if ($validation !== null) {
                return ['ok' => false, 'message' => $validation, 'code' => 'not_allowed'];
            }

            if ($locked->murguia_status === OdessaPreEnrollment::MURGUIA_ACTIVE) {
                return ['ok' => true, 'message' => 'La preafiliación ya está activa en Murguía.', 'code' => 'already_active'];
            }

            if ($locked->murguia_status === OdessaPreEnrollment::MURGUIA_PENDING && ! $this->leaseExpired($locked)) {
                return ['ok' => false, 'message' => 'Hay una operación Murguía en curso.', 'code' => 'operation_in_progress'];
            }

            $contract = OdessaPreEnrollmentMurguiaRegistrationPayload::fromConfig();
            if (! ($contract['ok'] ?? false)) {
                $this->auditControlledEvent($locked, $actor, self::EVENT_CONTRACT_NOT_CONFIGURED, null);

                return ['ok' => false, 'message' => 'Configuración contractual Murguía pendiente.', 'code' => self::EVENT_CONTRACT_NOT_CONFIGURED];
            }

            /** @var OdessaPreEnrollmentMurguiaRegistrationPayload $payloadBuilder */
            $payloadBuilder = $contract['contract'];
            if (! $payloadBuilder->datesMatch($locked)) {
                $this->auditControlledEvent($locked, $actor, self::EVENT_MEMBERSHIP_DATES_MISMATCH, null);

                return ['ok' => false, 'message' => 'La vigencia preparada no coincide con la configuración contractual Murguía.', 'code' => self::EVENT_MEMBERSHIP_DATES_MISMATCH];
            }

            $before = $this->auditState($locked);
            $payloadBuilder->applyDates($locked);
            $locked->forceFill([
                'murguia_status' => OdessaPreEnrollment::MURGUIA_PENDING,
                'murguia_correlation_id' => (string) Str::uuid(),
                'murguia_operation_token' => (string) Str::uuid(),
                'murguia_attempts' => (int) ($locked->murguia_attempts ?? 0) + 1,
                'murguia_pending_since' => now(),
                'murguia_last_http_status' => null,
                'murguia_last_event_code' => self::EVENT_REGISTER_STARTED,
            ])->save();

            $this->audit($locked, $actor, self::EVENT_REGISTER_STARTED, $before, $this->auditState($locked), null);

            return [
                'ok' => true,
                'payload' => $payloadBuilder->toArray($locked),
                'operation_token' => $locked->murguia_operation_token,
                'correlation_id' => $locked->murguia_correlation_id,
            ];
        });
    }

    private function sendRegister(array $payload): Response
    {
        $authResponse = ($this->authorizationAction)();
        $token = (string) ($authResponse->json()['token'] ?? '');

        return Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->post(config('services.murguia.url').'asegurados/registro', $payload);
    }

    private function validatePreEnrollment(OdessaPreEnrollment $preEnrollment): ?string
    {
        if ($preEnrollment->status !== OdessaPreEnrollment::STATUS_READY) {
            return 'Sólo se puede operar una preafiliación READY.';
        }
        if (! in_array($preEnrollment->murguia_status, [
            OdessaPreEnrollment::MURGUIA_NOT_REQUESTED,
            OdessaPreEnrollment::MURGUIA_FAILED,
            OdessaPreEnrollment::MURGUIA_INACTIVE,
            OdessaPreEnrollment::MURGUIA_PENDING,
        ], true)) {
            return 'El estado Murguía actual no permite alta.';
        }
        if ($preEnrollment->membership_type !== 'institutional') {
            return 'Sólo se puede operar una membresía institucional.';
        }
        if (! $this->identifierAction->isValidIdentifier($preEnrollment->medical_attention_identifier)) {
            return 'La preafiliación no tiene identificador reservado.';
        }
        if (! $preEnrollment->first_name || ! $preEnrollment->paternal_last_name || ! $preEnrollment->birth_date) {
            return 'La preafiliación no tiene identidad mínima completa.';
        }
        if (! empty($preEnrollment->data_quality_flags)) {
            return 'La preafiliación tiene alertas de calidad o conflicto.';
        }

        return null;
    }

    private function isAcceptedOrDuplicate(Response $response): bool
    {
        if ($response->successful() || $response->status() === 409) {
            return true;
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $message = strtolower((string) ($body['message'] ?? $body['mensaje'] ?? $body['error'] ?? ''));

        return str_contains($message, 'duplic') || str_contains($message, 'existe');
    }

    private function isFunctionalRejection(Response $response): bool
    {
        return in_array($response->status(), [400, 422], true);
    }

    private function markAccepted(OdessaPreEnrollment $preEnrollment, User $actor, ?int $httpStatus, string $operationToken, string $correlationId): array
    {
        return $this->updateAfterRegister($preEnrollment, $actor, self::EVENT_REGISTER_ACCEPTED, $httpStatus, [
            'murguia_registration_acknowledged_at' => now(),
        ], $operationToken, $correlationId);
    }

    private function markRejected(OdessaPreEnrollment $preEnrollment, User $actor, ?int $httpStatus, string $operationToken, string $correlationId): array
    {
        return $this->updateAfterRegister($preEnrollment, $actor, self::EVENT_REGISTER_REJECTED, $httpStatus, [
            'murguia_status' => OdessaPreEnrollment::MURGUIA_FAILED,
            'murguia_pending_since' => null,
        ], $operationToken, $correlationId);
    }

    private function markUnknown(OdessaPreEnrollment $preEnrollment, User $actor, ?int $httpStatus, string $operationToken, string $correlationId): array
    {
        return $this->updateAfterRegister($preEnrollment, $actor, self::EVENT_REGISTER_OUTCOME_UNKNOWN, $httpStatus, [
            'murguia_status' => OdessaPreEnrollment::MURGUIA_PENDING,
            'murguia_pending_since' => now(),
        ], $operationToken, $correlationId);
    }

    private function updateAfterRegister(OdessaPreEnrollment $preEnrollment, User $actor, string $eventCode, ?int $httpStatus, array $attributes, string $operationToken, string $correlationId): array
    {
        return DB::transaction(function () use ($preEnrollment, $actor, $eventCode, $httpStatus, $attributes, $operationToken, $correlationId) {
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

            $locked->forceFill([
                ...$attributes,
                'murguia_last_http_status' => $httpStatus,
                'murguia_last_event_code' => $eventCode,
            ])->save();

            $this->audit($locked, $actor, $eventCode, $before, $this->auditState($locked), $httpStatus);

            return [
                'ok' => $eventCode !== self::EVENT_REGISTER_REJECTED,
                'status' => $locked->murguia_status,
                'code' => $eventCode,
                'message' => match ($eventCode) {
                    self::EVENT_REGISTER_ACCEPTED => 'Murguía aceptó el alta; se verificará el estado.',
                    self::EVENT_REGISTER_REJECTED => 'Murguía rechazó el alta con un código controlado.',
                    default => 'No se pudo confirmar el resultado del alta; se conserva operación pendiente.',
                },
            ];
        });
    }

    private function leaseExpired(OdessaPreEnrollment $preEnrollment): bool
    {
        $pendingSince = $preEnrollment->murguia_pending_since;
        if (! $pendingSince) {
            return true;
        }

        return $pendingSince->lt(now()->subMinutes(max(1, (int) config('famedic.odessa_pre_enrollments.murguia_lease_minutes', 5))));
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

    private function auditControlledEvent(OdessaPreEnrollment $preEnrollment, User $actor, string $eventCode, ?int $httpStatus): void
    {
        $state = $this->auditState($preEnrollment);
        $this->audit($preEnrollment, $actor, $eventCode, $state, $state, $httpStatus);
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
}
