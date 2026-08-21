<?php

namespace App\Services\ActiveCampaign;

class ActiveCampaignLocationMapper
{
    /**
     * @param  array<string, mixed>|null  $contactData
     * @return array{city: string|null, state: string|null, country: string|null, timezone: string|null, source: string}|null
     */
    public function fromContactData(?array $contactData): ?array
    {
        if ($contactData === null || $contactData === []) {
            return null;
        }

        $location = [
            'city' => $this->nullableString($contactData['geoCity'] ?? null),
            'state' => $this->nullableString($contactData['geoState'] ?? null),
            'country' => $this->country($contactData),
            'timezone' => $this->nullableString($contactData['geoTz'] ?? null),
            'source' => 'activecampaign',
        ];

        return $this->hasLocationValue($location) ? $location : null;
    }

    /**
     * @param  array<string, mixed>  $contactData
     */
    private function country(array $contactData): ?string
    {
        $country = $this->nullableString(
            $contactData['geo_country']
                ?? $contactData['geoCountry']
                ?? $contactData['country']
                ?? null
        );

        if ($country !== null) {
            return $country;
        }

        $countryCode = $this->nullableString($contactData['geoCountry2'] ?? null);
        if ($countryCode === null) {
            return null;
        }

        return match (mb_strtoupper($countryCode)) {
            'MX', 'MEX' => 'Mexico',
            'US', 'USA' => 'United States',
            default => mb_strtoupper($countryCode),
        };
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' && $value !== '0' && $value !== '0.000000' ? mb_substr($value, 0, 120) : null;
    }

    /**
     * @param  array{city: string|null, state: string|null, country: string|null, timezone: string|null, source: string}  $location
     */
    private function hasLocationValue(array $location): bool
    {
        return $location['city'] !== null
            || $location['state'] !== null
            || $location['country'] !== null
            || $location['timezone'] !== null;
    }
}
