<?php

namespace App\Services\LaboratoryResults\StructuredQa;

use App\Enums\LaboratoryStructuredResultPublicationApprovalStatus;
use App\Enums\LaboratoryStructuredResultPromotionStatus;
use App\Models\LaboratoryResultReport;

final class LaboratoryStructuredResultPublicationApproval
{
    public const APPROVAL_VERSION = 'publication_approval_v1';

    /**
     * @return array<string, mixed>
     */
    public static function read(LaboratoryResultReport $report): array
    {
        $payload = $report->raw_extraction_payload ?? [];
        $stored = $payload['publication_approval'] ?? [];

        $status = $stored['approval_status'] ?? null;

        if ($status === null && self::isPromotionValidated($report)) {
            $status = LaboratoryStructuredResultPublicationApprovalStatus::Pending->value;
        }

        return [
            'approval_version' => $stored['approval_version'] ?? self::APPROVAL_VERSION,
            'approval_status' => $status,
            'approved_by_user_id' => $stored['approved_by_user_id'] ?? null,
            'approved_by_name' => $stored['approved_by_name'] ?? null,
            'approved_at' => $stored['approved_at'] ?? null,
            'reason' => $stored['reason'] ?? null,
            'promotion_gate_version' => $stored['promotion_gate_version']
                ?? LaboratoryStructuredResultPromotionGateResult::GATE_VERSION,
            'previous_status' => $stored['previous_status'] ?? null,
            'content_snapshot_hash' => $stored['content_snapshot_hash'] ?? null,
            'rejected_by_user_id' => $stored['rejected_by_user_id'] ?? null,
            'rejected_by_name' => $stored['rejected_by_name'] ?? null,
            'rejected_at' => $stored['rejected_at'] ?? null,
            'rejection_reason' => $stored['rejection_reason'] ?? null,
        ];
    }

    public static function status(LaboratoryResultReport $report): ?LaboratoryStructuredResultPublicationApprovalStatus
    {
        $value = self::read($report)['approval_status'];

        if ($value === null) {
            return null;
        }

        return LaboratoryStructuredResultPublicationApprovalStatus::tryFrom((string) $value);
    }

    public static function isPromotionValidated(LaboratoryResultReport $report): bool
    {
        $report->loadMissing('observations');

        if ($report->observations->isEmpty()) {
            return false;
        }

        return $report->observations->every(function ($observation) {
            $promotion = $observation->metadata['promotion_evaluation']['promotion_status'] ?? 'shadow';

            return $promotion === LaboratoryStructuredResultPromotionStatus::Validated->value;
        });
    }
}
