<?php

namespace App\Services\Api\V1;

use App\Exceptions\Api\V1\PricingSourceUnavailableException;
use App\Models\LaboratoryTest;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class LaboratoryPricingService
{
    public function for(LaboratoryTest $laboratoryTest): array
    {
        $this->ensureSourceAvailable($laboratoryTest);

        $famedicPriceCents = (int) $laboratoryTest->famedic_price_cents;
        $publicPriceCents = $laboratoryTest->public_price_cents === null
            ? null
            : (int) $laboratoryTest->public_price_cents;

        $status = 'public_price_unavailable';
        $savingsCents = null;
        $savingsPercentage = null;

        if ($publicPriceCents !== null && $publicPriceCents > 0) {
            if ($publicPriceCents > $famedicPriceCents) {
                $savingsCents = $publicPriceCents - $famedicPriceCents;
                $savingsPercentage = round(($savingsCents / $publicPriceCents) * 100, 2);
                $status = 'available';
            } elseif ($publicPriceCents === $famedicPriceCents) {
                $savingsCents = 0;
                $savingsPercentage = 0.0;
                $status = 'no_savings';
            } else {
                $status = 'data_inconsistent';
            }
        }

        return [
            'public_price_cents' => $publicPriceCents,
            'famedic_price_cents' => $famedicPriceCents,
            'savings_cents' => $savingsCents,
            'savings_percentage' => $savingsPercentage,
            'currency' => 'MXN',
            'tax_included' => $this->taxIncluded(),
            'comparison_status' => $status,
            'effective_at' => $this->dateFromConfig('effective_at'),
            'valid_until' => $this->dateFromConfig('valid_until'),
        ];
    }

    private function ensureSourceAvailable(LaboratoryTest $laboratoryTest): void
    {
        if (! config('api_v1.pricing.source_available', true)
            || $laboratoryTest->famedic_price_cents === null
            || (int) $laboratoryTest->famedic_price_cents < 0) {
            throw new PricingSourceUnavailableException;
        }
    }

    private function taxIncluded(): ?bool
    {
        $value = config('api_v1.pricing.tax_included');

        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function dateFromConfig(string $key): ?string
    {
        $value = config("api_v1.pricing.{$key}");

        if ($value instanceof CarbonInterface) {
            return $value->toISOString();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toISOString();
        } catch (\Throwable) {
            return null;
        }
    }
}
