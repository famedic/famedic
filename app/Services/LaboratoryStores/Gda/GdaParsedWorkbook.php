<?php

namespace App\Services\LaboratoryStores\Gda;

class GdaParsedWorkbook
{
    public function __construct(
        public readonly array $stores,
        public readonly array $clinicalHistoryServices,
        public readonly array $opticalServices,
    ) {}
}
