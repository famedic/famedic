<?php

namespace App\Services\LaboratoryStores\Gda;

class GdaSpecialServiceRow
{
    public function __construct(
        public readonly string $sheet,
        public readonly int $rowNumber,
        public readonly string $serviceType,
        public readonly ?string $brand,
        public readonly ?string $storeName,
        public readonly ?string $name,
        public readonly ?string $scheduleRaw,
        public readonly ?string $phone,
        public readonly ?string $address,
        public readonly array $rawPayload,
    ) {}
}
