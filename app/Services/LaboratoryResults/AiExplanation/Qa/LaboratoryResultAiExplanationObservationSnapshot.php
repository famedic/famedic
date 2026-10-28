<?php

namespace App\Services\LaboratoryResults\AiExplanation\Qa;

use App\Models\LaboratoryResultObservation;

/** Snapshot clínico inmutable para QA de AI Explanation. */
final class LaboratoryResultAiExplanationObservationSnapshot
{
    /** @return array<string, mixed> */
    public static function capture(LaboratoryResultObservation $observation): array
    {
        return [
            'numeric_value' => $observation->numeric_value === null ? null : (string) $observation->numeric_value,
            'text_value' => $observation->text_value,
            'value_type' => $observation->value_type?->value,
            'unit' => $observation->unit,
            'reference_text' => $observation->reference_text,
            'reference_low' => $observation->reference_low === null ? null : (string) $observation->reference_low,
            'reference_high' => $observation->reference_high === null ? null : (string) $observation->reference_high,
            'reference_status' => $observation->reference_status?->value,
            'abnormal_flag' => $observation->abnormal_flag,
            'laboratory_analyte_id' => $observation->laboratory_analyte_id,
            'analyte_code' => $observation->analyte_code,
        ];
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    public static function assertUnchanged(array $before, array $after): bool
    {
        return $before === $after;
    }
}
