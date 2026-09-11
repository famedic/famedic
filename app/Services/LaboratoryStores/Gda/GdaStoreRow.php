<?php

namespace App\Services\LaboratoryStores\Gda;

class GdaStoreRow
{
    public function __construct(
        public readonly string $sheet,
        public readonly int $rowNumber,
        public readonly ?string $brand,
        public readonly ?string $name,
        public readonly ?string $state,
        public readonly ?string $street,
        public readonly ?string $exteriorNumber,
        public readonly ?string $interiorNumber,
        public readonly ?string $neighborhood,
        public readonly ?string $municipality,
        public readonly ?string $city,
        public readonly mixed $postalCode,
        public readonly mixed $phone,
        public readonly mixed $latitude,
        public readonly mixed $longitude,
        public readonly ?string $scheduleRaw,
        public readonly array $rawPayload,
    ) {}

    public function address(): ?string
    {
        $parts = array_filter([
            $this->street,
            $this->exteriorNumber,
            $this->interiorNumber,
            $this->neighborhood,
            $this->municipality,
            $this->city,
            $this->postalCode ? (string) $this->postalCode : null,
        ], fn ($part) => trim((string) $part) !== '');

        return $parts === [] ? null : implode(', ', $parts);
    }
}
