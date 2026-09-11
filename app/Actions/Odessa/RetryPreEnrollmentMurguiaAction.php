<?php

namespace App\Actions\Odessa;

use App\Models\OdessaPreEnrollment;
use App\Models\OdessaPreEnrollmentAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RetryPreEnrollmentMurguiaAction
{
    public const EVENT_RETRY_STARTED = 'MURGUIA_RETRY_STARTED';

    public function __construct(
        private VerifyPreEnrollmentMurguiaStatusAction $verifyAction,
        private RegisterPreEnrollmentWithMurguiaAction $registerAction,
    ) {}

    public function execute(OdessaPreEnrollment $preEnrollment, User $actor): array
    {
        if (! config('famedic.odessa_pre_enrollments.murguia_enabled', false)) {
            return ['ok' => false, 'message' => 'La verificación Murguía está deshabilitada por configuración.', 'code' => 'flag_off'];
        }

        if (! config('famedic.odessa_pre_enrollments.murguia_retry_enabled', false)) {
            return ['ok' => false, 'message' => 'El reintento Murguía está deshabilitado por configuración.', 'code' => 'retry_flag_off'];
        }

        $started = $this->markRetryStarted($preEnrollment, $actor);
        if (! ($started['ok'] ?? false)) {
            return $started;
        }

        $readback = $this->verifyAction->execute($preEnrollment->fresh(), $actor);
        $current = $preEnrollment->fresh();

        if (in_array($current->murguia_status, [OdessaPreEnrollment::MURGUIA_ACTIVE, OdessaPreEnrollment::MURGUIA_INACTIVE], true)) {
            return $readback;
        }

        if (($readback['code'] ?? null) !== VerifyPreEnrollmentMurguiaStatusAction::EVENT_READBACK_NOT_FOUND) {
            return $readback;
        }

        return $this->registerAction->execute($current, $actor);
    }

    private function markRetryStarted(OdessaPreEnrollment $preEnrollment, User $actor): array
    {
        return DB::transaction(function () use ($preEnrollment, $actor) {
            $locked = OdessaPreEnrollment::query()
                ->whereKey($preEnrollment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->murguia_status === OdessaPreEnrollment::MURGUIA_PENDING && $locked->murguia_pending_since?->gt(now()->subMinutes(max(1, (int) config('famedic.odessa_pre_enrollments.murguia_lease_minutes', 5))))) {
                return ['ok' => false, 'message' => 'Hay una operación Murguía en curso.', 'code' => 'operation_in_progress'];
            }

            $before = $this->auditState($locked);
            $locked->forceFill([
                'murguia_last_event_code' => self::EVENT_RETRY_STARTED,
            ])->save();

            OdessaPreEnrollmentAudit::create([
                'odessa_pre_enrollment_id' => $locked->id,
                'performed_by' => $actor->id,
                'action_type' => self::EVENT_RETRY_STARTED,
                'before_json' => $before,
                'after_json' => [
                    ...$this->auditState($locked),
                    'event_code' => self::EVENT_RETRY_STARTED,
                ],
                'reason' => null,
                'performed_at' => now(),
            ]);

            return ['ok' => true];
        });
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
