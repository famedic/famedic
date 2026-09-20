<?php

namespace App\Http\Resources;

use App\Enums\LaboratoryResultObservationValueType;
use App\Enums\LaboratoryResultReferenceStatus;
use App\Models\LaboratoryResultObservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LaboratoryResultObservation
 */
class LaboratoryStructuredPatientObservationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LaboratoryResultObservation $observation */
        $observation = $this->resource;

        return [
            'id' => $observation->id,
            'analyte' => [
                'code' => $observation->analyte_code,
                'name' => $observation->analyte_name_display
                    ?: $observation->analyte_name_raw,
            ],
            'value' => $this->resolveValue($observation),
            'value_type' => $observation->value_type?->value
                ?? LaboratoryResultObservationValueType::Unknown->value,
            'unit' => $observation->unit,
            'reference' => [
                'text' => $observation->reference_text,
                'low' => $this->nullableDecimal($observation->reference_low),
                'high' => $this->nullableDecimal($observation->reference_high),
            ],
            'status' => $observation->reference_status?->value
                ?? LaboratoryResultReferenceStatus::Unknown->value,
            'abnormal' => $this->resolveAbnormalFlag($observation),
        ];
    }

    private function resolveValue(LaboratoryResultObservation $observation): mixed
    {
        return match ($observation->value_type) {
            LaboratoryResultObservationValueType::Numeric => $this->nullableDecimal($observation->numeric_value),
            LaboratoryResultObservationValueType::Qualitative,
            LaboratoryResultObservationValueType::Comment => $observation->text_value,
            default => $observation->numeric_value !== null
                ? $this->nullableDecimal($observation->numeric_value)
                : $observation->text_value,
        };
    }

    private function resolveAbnormalFlag(LaboratoryResultObservation $observation): bool
    {
        if ($observation->abnormal_flag !== null) {
            return (bool) $observation->abnormal_flag;
        }

        $status = $observation->reference_status;

        if ($status === null) {
            return false;
        }

        return ! in_array($status, [
            LaboratoryResultReferenceStatus::Normal,
            LaboratoryResultReferenceStatus::NotApplicable,
            LaboratoryResultReferenceStatus::Unknown,
        ], true);
    }

    private function nullableDecimal(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return (float) $value;
    }
}
