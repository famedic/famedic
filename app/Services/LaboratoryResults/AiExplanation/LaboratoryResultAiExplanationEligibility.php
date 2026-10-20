<?php

namespace App\Services\LaboratoryResults\AiExplanation;

use App\Enums\LaboratoryResultObservationValueType;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryResultObservation;
use App\Models\LaboratoryResultReport;

final class LaboratoryResultAiExplanationEligibility
{
    public function isEligible(LaboratoryResultObservation $observation, LaboratoryPurchase $purchase): bool
    {
        return $this->ineligibilityReason($observation, $purchase) === null;
    }

    public function ineligibilityReason(LaboratoryResultObservation $observation, LaboratoryPurchase $purchase): ?string
    {
        $observation->loadMissing('report');

        $report = $observation->report;
        if (! $report instanceof LaboratoryResultReport) {
            return 'missing_report';
        }

        if ((int) $report->laboratory_purchase_id !== (int) $purchase->id) {
            return 'purchase_mismatch';
        }

        if (! LaboratoryResultReport::query()
            ->whereKey($report->id)
            ->where('laboratory_purchase_id', $purchase->id)
            ->activePublished()
            ->exists()) {
            return 'not_published';
        }

        $analyteName = $observation->analyte_name_display ?: $observation->analyte_name_raw;
        if (! filled($analyteName)) {
            return 'missing_analyte';
        }

        if (! $this->hasValidValue($observation)) {
            return 'missing_value';
        }

        if ($observation->reference_status === null) {
            return 'missing_status';
        }

        return null;
    }

    private function hasValidValue(LaboratoryResultObservation $observation): bool
    {
        return match ($observation->value_type) {
            LaboratoryResultObservationValueType::Numeric => $observation->numeric_value !== null,
            LaboratoryResultObservationValueType::Qualitative,
            LaboratoryResultObservationValueType::Comment => filled($observation->text_value),
            default => $observation->numeric_value !== null || filled($observation->text_value),
        };
    }
}
