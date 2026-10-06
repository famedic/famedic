<?php

namespace App\Services\LaboratoryBilling;

class LaboratoryBillingNavCounts
{
    public function __construct(
        private LaboratoryBillingAwaitingSampleQuery $awaitingSampleQuery,
    ) {}

    /**
     * @return array{awaiting_sample: int}
     */
    public function toArray(): array
    {
        return [
            'awaiting_sample' => $this->awaitingSampleQuery->count(),
        ];
    }
}
