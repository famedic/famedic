<?php

namespace App\Services\Marketing\Targets;

use App\Models\LaboratoryTest;

class MarketingCampaignLandingProductMapper
{
    /**
     * @param  array<string, string>  $allowedQuery
     * @return array<string, mixed>
     */
    public function map(LaboratoryTest $test, array $allowedQuery = []): array
    {
        $shortDescription = $test->description
            ?: $test->common_use
            ?: $test->indications;

        return [
            'id' => $test->id,
            'name' => $test->name,
            'other_name' => $test->other_name,
            'category' => $test->laboratoryTestCategory?->name,
            'brand' => $test->brand?->value,
            'brand_label' => $test->brand?->label(),
            'public_price_cents' => (int) $test->public_price_cents,
            'famedic_price_cents' => (int) $test->famedic_price_cents,
            'formatted_public_price' => $test->formatted_public_price,
            'formatted_famedic_price' => $test->formatted_famedic_price,
            'requires_appointment' => (bool) $test->requires_appointment,
            'short_description' => $shortDescription ? (string) $shortDescription : null,
            'description' => $test->description ? (string) $test->description : null,
            'elements' => $test->elements ? (string) $test->elements : null,
            'common_use' => $test->common_use ? (string) $test->common_use : null,
            'indications' => $test->indications ? (string) $test->indications : null,
            'feature_list' => is_array($test->feature_list) ? array_values($test->feature_list) : [],
            'detail_url' => route('laboratory-tests.test', [
                'laboratory_test' => $test->id,
                ...$allowedQuery,
            ]),
        ];
    }
}
