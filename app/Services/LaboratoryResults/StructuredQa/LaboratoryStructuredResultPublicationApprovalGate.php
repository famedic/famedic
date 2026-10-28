<?php

namespace App\Services\LaboratoryResults\StructuredQa;

use App\Enums\LaboratoryStructuredResultPromotionStatus;
use App\Models\LaboratoryResultReport;

class LaboratoryStructuredResultPublicationApprovalGate
{
    public function __construct(
        private readonly LaboratoryStructuredResultPromotionGate $promotionGate,
    ) {}

    public function evaluate(LaboratoryResultReport $report): LaboratoryStructuredResultPublicationApprovalGateResult
    {
        $report->loadMissing(['observations.analyte', 'resultVersion']);

        $reasonCodes = [];
        $reasonsHuman = [];

        if (! $report->isShadowQa()) {
            return $this->blocked(
                ['not_shadow_qa'],
                ['El reporte no pertenece a Shadow QA.'],
            );
        }

        if ($report->laboratory_result_version_id === null) {
            return $this->blocked(
                ['version_missing'],
                ['Versión documental ausente.'],
            );
        }

        if ($report->observations->isEmpty()) {
            return $this->blocked(
                ['report_incomplete'],
                ['El reporte no tiene observaciones.'],
            );
        }

        foreach ($report->observations as $observation) {
            $gateResult = $this->promotionGate->evaluate($observation);
            $storedStatusValue = $observation->metadata['promotion_evaluation']['promotion_status'] ?? null;
            $storedStatus = $storedStatusValue !== null
                ? LaboratoryStructuredResultPromotionStatus::tryFrom((string) $storedStatusValue)
                : null;

            $promotionValidated = ($storedStatus === null || $storedStatus === LaboratoryStructuredResultPromotionStatus::Validated)
                && $gateResult->status === LaboratoryStructuredResultPromotionStatus::Validated;

            if (! $promotionValidated) {
                $reasonCodes[] = 'observation_not_validated';
                $detailCodes = $gateResult->reasonCodes;

                if ($storedStatus !== null && $storedStatus !== LaboratoryStructuredResultPromotionStatus::Validated) {
                    $storedCodes = $observation->metadata['promotion_evaluation']['reason_codes'] ?? [];
                    $detailCodes = is_array($storedCodes) && $storedCodes !== []
                        ? $storedCodes
                        : [$storedStatus->value];
                }

                $reasonsHuman[] = sprintf(
                    'Observación %d no cumple promotion gate (%s).',
                    $observation->id,
                    implode(', ', $detailCodes ?: ['unknown']),
                );
            }
        }

        if ($reasonCodes !== []) {
            return $this->blocked($reasonCodes, $reasonsHuman);
        }

        return new LaboratoryStructuredResultPublicationApprovalGateResult(
            canApprove: true,
            reasonCodes: [],
            reasonsHuman: [],
        );
    }

    /**
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $reasonsHuman
     */
    private function blocked(array $reasonCodes, array $reasonsHuman): LaboratoryStructuredResultPublicationApprovalGateResult
    {
        return new LaboratoryStructuredResultPublicationApprovalGateResult(
            canApprove: false,
            reasonCodes: $reasonCodes,
            reasonsHuman: $reasonsHuman,
        );
    }
}
