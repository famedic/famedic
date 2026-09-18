<?php

namespace App\Support\LaboratoryRequirements;

class GeoPoint
{
    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
    ) {}
}
