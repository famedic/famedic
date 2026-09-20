<?php

namespace App\Services\LaboratoryResults\StructuredQa;

use App\Enums\LaboratoryResultEventType;
use App\Enums\LaboratoryStructuredResultPublicationApprovalStatus;
use App\Models\LaboratoryResultEvent;
use App\Models\LaboratoryResultReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LaboratoryStructuredResultApprovalService
{
    private const MAX_REASON_LENGTH = 500;

    public function __construct(
        private readonly LaboratoryStructuredResultPublicationApprovalGate $approvalGate,
    ) {}

    public function approve(
        LaboratoryResultReport $report,
        User $user,
        ?string $reason = null,
    ): LaboratoryStructuredResultApprovalResult {
        return DB::transaction(function () use ($report, $user, $reason) {
            $report = LaboratoryResultReport::query()->lockForUpdate()->findOrFail($report->id);
            $current = LaboratoryStructuredResultPublicationApproval::read($report);
            $currentStatus = LaboratoryStructuredResultPublicationApprovalStatus::tryFrom(
                (string) ($current['approval_status'] ?? LaboratoryStructuredResultPublicationApprovalStatus::Pending->value),
            ) ?? LaboratoryStructuredResultPublicationApprovalStatus::Pending;

            if ($currentStatus === LaboratoryStructuredResultPublicationApprovalStatus::Approved) {
                return new LaboratoryStructuredResultApprovalResult(
                    report: $report,
                    status: $currentStatus,
                    idempotent: true,
                    approval: $current,
                );
            }

            if ($currentStatus === LaboratoryStructuredResultPublicationApprovalStatus::Rejected) {
                throw new LaboratoryStructuredResultApprovalException(
                    reasonCode: 'approval_rejected_terminal',
                    message: 'Este reporte fue rechazado y no puede aprobarse sin una nueva evaluación.',
                    reasonCodes: ['approval_rejected_terminal'],
                    reasonsHuman: ['Aprobación bloqueada: estado REJECTED terminal.'],
                );
            }

            $gateResult = $this->approvalGate->evaluate($report);

            if (! $gateResult->canApprove) {
                throw new LaboratoryStructuredResultApprovalException(
                    reasonCode: 'publication_gate_blocked',
                    message: 'El reporte no cumple los requisitos para aprobación.',
                    reasonCodes: $gateResult->reasonCodes,
                    reasonsHuman: $gateResult->reasonsHuman,
                );
            }

            $previousStatus = $currentStatus->value;
            $sanitizedReason = $this->sanitizeReason($reason);

            $payload = $report->raw_extraction_payload ?? [];
            $payload['publication_approval'] = [
                'approval_version' => LaboratoryStructuredResultPublicationApproval::APPROVAL_VERSION,
                'approval_status' => LaboratoryStructuredResultPublicationApprovalStatus::Approved->value,
                'approved_by_user_id' => $user->id,
                'approved_by_name' => $user->name,
                'approved_at' => now()->toIso8601String(),
                'reason' => $sanitizedReason,
                'promotion_gate_version' => LaboratoryStructuredResultPromotionGateResult::GATE_VERSION,
                'previous_status' => $previousStatus,
                'content_snapshot_hash' => LaboratoryStructuredResultContentSnapshot::compute($report),
            ];

            $report->update(['raw_extraction_payload' => $payload]);

            $this->recordEvent(
                $report,
                LaboratoryResultEventType::StructuredResultApproved,
                [
                    'report_id' => $report->id,
                    'version_id' => $report->laboratory_result_version_id,
                    'previous_approval_status' => $previousStatus,
                    'new_approval_status' => LaboratoryStructuredResultPublicationApprovalStatus::Approved->value,
                    'approval_version' => LaboratoryStructuredResultPublicationApproval::APPROVAL_VERSION,
                    'promotion_gate_version' => LaboratoryStructuredResultPromotionGateResult::GATE_VERSION,
                    'reason' => $sanitizedReason,
                    'observation_count' => $report->observations()->count(),
                ],
                $user,
            );

            return new LaboratoryStructuredResultApprovalResult(
                report: $report->fresh(),
                status: LaboratoryStructuredResultPublicationApprovalStatus::Approved,
                idempotent: false,
                approval: LaboratoryStructuredResultPublicationApproval::read($report->fresh()),
            );
        });
    }

    public function reject(
        LaboratoryResultReport $report,
        User $user,
        ?string $reason = null,
    ): LaboratoryStructuredResultApprovalResult {
        return DB::transaction(function () use ($report, $user, $reason) {
            $report = LaboratoryResultReport::query()->lockForUpdate()->findOrFail($report->id);
            $current = LaboratoryStructuredResultPublicationApproval::read($report);
            $currentStatus = LaboratoryStructuredResultPublicationApprovalStatus::tryFrom(
                (string) ($current['approval_status'] ?? LaboratoryStructuredResultPublicationApprovalStatus::Pending->value),
            ) ?? LaboratoryStructuredResultPublicationApprovalStatus::Pending;

            if ($currentStatus === LaboratoryStructuredResultPublicationApprovalStatus::Rejected) {
                return new LaboratoryStructuredResultApprovalResult(
                    report: $report,
                    status: $currentStatus,
                    idempotent: true,
                    approval: $current,
                );
            }

            if ($currentStatus === LaboratoryStructuredResultPublicationApprovalStatus::Approved) {
                throw new LaboratoryStructuredResultApprovalException(
                    reasonCode: 'already_approved',
                    message: 'No se puede rechazar un reporte ya aprobado.',
                    reasonCodes: ['already_approved'],
                    reasonsHuman: ['El reporte ya está APPROVED.'],
                );
            }

            if (! $report->isShadowQa()) {
                throw new LaboratoryStructuredResultApprovalException(
                    reasonCode: 'not_shadow_qa',
                    message: 'Solo reportes Shadow QA pueden rechazarse en este flujo.',
                    reasonCodes: ['not_shadow_qa'],
                    reasonsHuman: ['Reporte no es Shadow QA.'],
                );
            }

            $sanitizedReason = $this->sanitizeReason($reason, required: true);
            $previousStatus = $currentStatus->value;

            $payload = $report->raw_extraction_payload ?? [];
            $payload['publication_approval'] = [
                'approval_version' => LaboratoryStructuredResultPublicationApproval::APPROVAL_VERSION,
                'approval_status' => LaboratoryStructuredResultPublicationApprovalStatus::Rejected->value,
                'rejected_by_user_id' => $user->id,
                'rejected_by_name' => $user->name,
                'rejected_at' => now()->toIso8601String(),
                'rejection_reason' => $sanitizedReason,
                'promotion_gate_version' => LaboratoryStructuredResultPromotionGateResult::GATE_VERSION,
                'previous_status' => $previousStatus,
            ];

            $report->update(['raw_extraction_payload' => $payload]);

            $this->recordEvent(
                $report,
                LaboratoryResultEventType::StructuredResultApprovalRejected,
                [
                    'report_id' => $report->id,
                    'version_id' => $report->laboratory_result_version_id,
                    'previous_approval_status' => $previousStatus,
                    'new_approval_status' => LaboratoryStructuredResultPublicationApprovalStatus::Rejected->value,
                    'approval_version' => LaboratoryStructuredResultPublicationApproval::APPROVAL_VERSION,
                    'promotion_gate_version' => LaboratoryStructuredResultPromotionGateResult::GATE_VERSION,
                    'reason' => $sanitizedReason,
                    'observation_count' => $report->observations()->count(),
                ],
                $user,
            );

            return new LaboratoryStructuredResultApprovalResult(
                report: $report->fresh(),
                status: LaboratoryStructuredResultPublicationApprovalStatus::Rejected,
                idempotent: false,
                approval: LaboratoryStructuredResultPublicationApproval::read($report->fresh()),
            );
        });
    }

    private function sanitizeReason(?string $reason, bool $required = false): ?string
    {
        $trimmed = $reason !== null ? trim($reason) : null;

        if ($required && ($trimmed === null || $trimmed === '')) {
            throw new LaboratoryStructuredResultApprovalException(
                reasonCode: 'reason_required',
                message: 'Se requiere una razón para el rechazo.',
                reasonCodes: ['reason_required'],
                reasonsHuman: ['Razón de rechazo requerida.'],
            );
        }

        if ($trimmed === null || $trimmed === '') {
            return null;
        }

        return Str::limit($trimmed, self::MAX_REASON_LENGTH, '');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordEvent(
        LaboratoryResultReport $report,
        LaboratoryResultEventType $eventType,
        array $metadata,
        User $user,
    ): void {
        $statusId = $report->resultVersion?->laboratory_result_status_id;

        if ($statusId === null) {
            return;
        }

        LaboratoryResultEvent::query()->create([
            'laboratory_result_status_id' => $statusId,
            'laboratory_result_version_id' => $report->laboratory_result_version_id,
            'event_type' => $eventType,
            'actor_type' => User::class,
            'actor_id' => $user->id,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
