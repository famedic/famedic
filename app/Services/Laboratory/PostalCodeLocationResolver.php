<?php

namespace App\Services\Laboratory;

use App\Enums\LaboratoryBrand;
use App\Models\PostalCodeLocation;
use App\Support\LaboratoryRequirements\GeoPoint;
use Illuminate\Support\Facades\Schema;

class PostalCodeLocationResolver
{
    /**
     * @return array{
     *     status: string,
     *     postal_code: string|null,
     *     location: GeoPoint|null,
     *     source: string|null,
     *     confidence: string|null,
     *     matches_count: int,
     * }
     */
    public function resolve(?string $postalCode, ?LaboratoryBrand $brand = null): array
    {
        if ($postalCode === null) {
            return $this->result('missing', null);
        }

        if (! Schema::hasTable('postal_code_locations')) {
            return $this->result('unresolved', $postalCode);
        }

        $location = PostalCodeLocation::query()
            ->where('postal_code', $postalCode)
            ->first(['postal_code', 'latitude', 'longitude', 'source', 'confidence']);

        if ($location === null) {
            return $this->result('unresolved', $postalCode);
        }

        return $this->result(
            'resolved',
            $postalCode,
            new GeoPoint(
                latitude: round((float) $location->latitude, 6),
                longitude: round((float) $location->longitude, 6),
            ),
            'postal_code_locations:'.$location->source,
            $location->confidence,
            1,
        );
    }

    private function result(
        string $status,
        ?string $postalCode,
        ?GeoPoint $location = null,
        ?string $source = null,
        ?string $confidence = null,
        int $matchesCount = 0,
    ): array {
        return [
            'status' => $status,
            'postal_code' => $postalCode,
            'location' => $location,
            'source' => $source,
            'confidence' => $confidence,
            'matches_count' => $matchesCount,
        ];
    }
}
