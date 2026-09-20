<?php

namespace App\Services\LaboratoryResults\AiExplanation\Contract;

use App\Enums\LaboratoryResultObservationValueType;
use App\Enums\LaboratoryResultReferenceStatus;
use App\Models\LaboratoryResultObservation;
use InvalidArgumentException;

final class LaboratoryResultAiExplanationInputBuilder
{
    /** @return array<string, mixed> */
    public function fromObservation(LaboratoryResultObservation $observation): array
    {
        $payload = [
            'analyte' => [
                'code' => $observation->analyte_code,
                'name' => $observation->analyte_name_display ?: $observation->analyte_name_raw,
            ],
            'result' => [
                'value' => $this->resolveValue($observation),
                'value_type' => ($observation->value_type ?? LaboratoryResultObservationValueType::Unknown)->value,
                'unit' => $observation->unit,
            ],
            'reference' => [
                'text' => $observation->reference_text,
                'low' => $this->nullableFloat($observation->reference_low),
                'high' => $this->nullableFloat($observation->reference_high),
            ],
            'status' => ($observation->reference_status ?? LaboratoryResultReferenceStatus::Unknown)->value,
            'abnormal' => $this->resolveAbnormal($observation),
        ];

        $this->assertNoForbiddenKeys($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    public function assertNoForbiddenKeys(array $payload): void
    {
        $extraTopLevel = array_diff(array_keys($payload), LaboratoryResultAiExplanationContract::INPUT_ALLOWED_TOP_LEVEL_KEYS);
        if ($extraTopLevel !== []) {
            throw new InvalidArgumentException('Forbidden AI explanation input keys: '.implode(', ', $extraTopLevel));
        }

        foreach (LaboratoryResultAiExplanationContract::INPUT_FORBIDDEN_KEYS as $forbidden) {
            if (array_key_exists($forbidden, $payload)) {
                throw new InvalidArgumentException("Forbidden AI explanation input key: {$forbidden}");
            }
        }

        $this->assertExactKeys($payload['analyte'] ?? [], LaboratoryResultAiExplanationContract::INPUT_ANALYTE_KEYS, 'analyte');
        $this->assertExactKeys($payload['result'] ?? [], LaboratoryResultAiExplanationContract::INPUT_RESULT_KEYS, 'result');
        $this->assertExactKeys($payload['reference'] ?? [], LaboratoryResultAiExplanationContract::INPUT_REFERENCE_KEYS, 'reference');
    }

    /** @param array<string, mixed> $section @param list<string> $allowed */
    private function assertExactKeys(array $section, array $allowed, string $label): void
    {
        $extra = array_diff(array_keys($section), $allowed);
        if ($extra !== []) {
            throw new InvalidArgumentException("Forbidden AI explanation input keys in {$label}: ".implode(', ', $extra));
        }
    }

    private function resolveValue(LaboratoryResultObservation $observation): mixed
    {
        return match ($observation->value_type) {
            LaboratoryResultObservationValueType::Numeric => $this->nullableFloat($observation->numeric_value),
            LaboratoryResultObservationValueType::Qualitative,
            LaboratoryResultObservationValueType::Comment => $observation->text_value,
            default => $observation->numeric_value !== null
                ? $this->nullableFloat($observation->numeric_value)
                : $observation->text_value,
        };
    }

    private function resolveAbnormal(LaboratoryResultObservation $observation): bool
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

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
