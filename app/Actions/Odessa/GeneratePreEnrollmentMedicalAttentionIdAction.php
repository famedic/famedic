<?php

namespace App\Actions\Odessa;

use App\Actions\Customers\GenerateUniqueMedicalAttentionIdAction;
use App\Models\OdessaPreEnrollment;
use App\Models\OdessaPreEnrollmentAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GeneratePreEnrollmentMedicalAttentionIdAction
{
    public const EVENT_CREDIT_RESERVED = 'CREDIT_RESERVED';

    public function __construct(
        private GenerateUniqueMedicalAttentionIdAction $generateUniqueMedicalAttentionIdAction,
    ) {}

    public function preview(OdessaPreEnrollment $preEnrollment): array
    {
        $allowed = $this->isAllowed($preEnrollment);

        return [
            'allowed' => $allowed,
            'message' => $allowed
                ? 'Se reservará un identificador único de 10 dígitos para esta preafiliación. No se creará Customer ni se llamará Murguía.'
                : $this->blockedReason($preEnrollment),
            'before' => $this->auditState($preEnrollment->only(['medical_attention_identifier', 'status', 'link_status'])),
        ];
    }

    public function execute(OdessaPreEnrollment $preEnrollment, User $actor, string $reason): array
    {
        if (! config('famedic.odessa_pre_enrollments.generate_credit_enabled', false)) {
            return ['ok' => false, 'message' => 'La generación de noCredito para preafiliaciones está deshabilitada por configuración.'];
        }

        if (! $this->infrastructureIsAvailable()) {
            return ['ok' => false, 'message' => 'La infraestructura de reserva de noCredito no está disponible.'];
        }

        if ($preEnrollment->medical_attention_identifier) {
            return [
                'ok' => true,
                'message' => 'La preafiliación ya tenía identificador reservado.',
            ];
        }

        if (! $this->isAllowed($preEnrollment)) {
            return ['ok' => false, 'message' => $this->blockedReason($preEnrollment)];
        }

        return DB::transaction(function () use ($preEnrollment, $actor, $reason) {
            $preEnrollment = OdessaPreEnrollment::query()
                ->whereKey($preEnrollment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($preEnrollment->medical_attention_identifier) {
                return [
                    'ok' => true,
                    'message' => 'La preafiliación ya tenía identificador reservado.',
                ];
            }

            if (! $this->isAllowed($preEnrollment)) {
                return ['ok' => false, 'message' => $this->blockedReason($preEnrollment)];
            }

            $before = $preEnrollment->only(['medical_attention_identifier', 'status', 'link_status']);

            ($this->generateUniqueMedicalAttentionIdAction)(
                persist: function (int $code) use ($preEnrollment) {
                    $preEnrollment->forceFill(['medical_attention_identifier' => (string) $code])->save();
                },
                purpose: 'pre_enrollment_generated',
                reservableType: OdessaPreEnrollment::class,
                reservableId: $preEnrollment->id,
            );

            OdessaPreEnrollmentAudit::create([
                'odessa_pre_enrollment_id' => $preEnrollment->id,
                'performed_by' => $actor->id,
                'action_type' => self::EVENT_CREDIT_RESERVED,
                'before_json' => $this->auditState($before),
                'after_json' => $this->auditState($preEnrollment->fresh()->only(['medical_attention_identifier', 'status', 'link_status'])),
                'reason' => $reason,
                'performed_at' => now(),
            ]);

            return [
                'ok' => true,
                'message' => 'Identificador reservado para la preafiliación.',
            ];
        });
    }

    private function isAllowed(OdessaPreEnrollment $preEnrollment): bool
    {
        return $preEnrollment->status === OdessaPreEnrollment::STATUS_READY
            && $preEnrollment->medical_attention_identifier === null
            && ! in_array($preEnrollment->link_status, [
                OdessaPreEnrollment::LINK_IDENTITY_CONFLICT,
                OdessaPreEnrollment::LINK_POSSIBLE_DUPLICATE,
            ], true)
            && empty($preEnrollment->data_quality_flags);
    }

    private function blockedReason(OdessaPreEnrollment $preEnrollment): string
    {
        if ($preEnrollment->medical_attention_identifier) {
            return 'La preafiliación ya tiene identificador reservado.';
        }
        if ($preEnrollment->status !== OdessaPreEnrollment::STATUS_READY) {
            return 'Solo se puede reservar identificador para preafiliaciones READY.';
        }
        if (! empty($preEnrollment->data_quality_flags)) {
            return 'La preafiliación tiene alertas de calidad o conflicto.';
        }

        return 'La preafiliación no cumple las reglas para generar noCredito.';
    }

    private function infrastructureIsAvailable(): bool
    {
        return Schema::hasTable('odessa_pre_enrollments')
            && Schema::hasTable('medical_attention_identifier_reservations');
    }

    private function auditState(array $state): array
    {
        return [
            'has_medical_attention_identifier' => filled($state['medical_attention_identifier'] ?? null),
            'status' => $state['status'] ?? null,
            'link_status' => $state['link_status'] ?? null,
        ];
    }
}
