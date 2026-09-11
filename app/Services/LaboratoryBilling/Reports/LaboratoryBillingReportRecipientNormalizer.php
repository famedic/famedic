<?php

namespace App\Services\LaboratoryBilling\Reports;

class LaboratoryBillingReportRecipientNormalizer
{
    /**
     * @return array<int, string>
     */
    public function normalize(array|string|null $value): array
    {
        $items = is_array($value)
            ? $value
            : preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);

        return collect($items ?: [])
            ->map(fn ($email) => mb_strtolower(trim((string) $email)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
