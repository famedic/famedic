<?php

namespace App\Services\LaboratoryStores\Gda;

use App\Models\LaboratoryStore;

class GdaFieldConflictDetector
{
    /**
     * Intentionally small: catches obvious conflicts for the states represented
     * in this workbook without becoming a national postal-code catalog.
     */
    private const POSTAL_CODE_STATE_RANGES = [
        'ciudad de mexico' => [[1000, 16999]],
        'estado de mexico' => [[50000, 57999]],
        'mexico' => [[50000, 57999]],
        'nuevo leon' => [[64000, 67999]],
        'chihuahua' => [[31000, 33999]],
    ];

    public function __construct(private readonly GdaStringNormalizer $normalizer) {}

    /**
     * @return array<string, array{source_value: mixed, existing_value: mixed, reason: string, action: string}>
     */
    public function detect(array $planned, ?LaboratoryStore $existingStore = null): array
    {
        if ($existingStore === null) {
            return [];
        }

        $state = $this->normalizer->normalize($planned['state'] ?? null);
        $postalCode = $planned['postal_code'] ?? null;

        if ($state === '' || ! is_string($postalCode) || $postalCode === '') {
            return [];
        }

        $expectedRanges = self::POSTAL_CODE_STATE_RANGES[$state] ?? null;

        if ($expectedRanges === null || $this->postalCodeIsInRanges((int) $postalCode, $expectedRanges)) {
            return [];
        }

        $reason = "postal_code {$postalCode} is outside the expected range for {$planned['state']}";

        return collect(['postal_code', 'address', 'neighborhood', 'municipality'])
            ->filter(fn (string $field) => array_key_exists($field, $planned) && $planned[$field] !== null && $planned[$field] !== '')
            ->mapWithKeys(fn (string $field) => [$field => [
                'source_value' => $planned[$field],
                'existing_value' => $existingStore->{$field},
                'reason' => $reason,
                'action' => 'SKIPPED_CONFLICT',
            ]])
            ->all();
    }

    private function postalCodeIsInRanges(int $postalCode, array $ranges): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($postalCode >= $start && $postalCode <= $end) {
                return true;
            }
        }

        return false;
    }
}
