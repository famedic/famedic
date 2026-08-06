<?php

namespace App\Services\Marketing\Targets;

use App\Enums\MarketingCampaignTargetType;
use App\Models\LaboratoryTest;
use App\Models\MarketingCampaignCollection;
use App\Models\MarketingCampaignLink;

class CollectionCampaignTargetResolver implements MarketingCampaignTargetResolver
{
    public function supports(MarketingCampaignTargetType $type): bool
    {
        return $type === MarketingCampaignTargetType::Collection;
    }

    public function resolve(MarketingCampaignLink $link, array $allowedQuery): MarketingCampaignTargetResolution
    {
        $payload = is_array($link->target_payload) ? $link->target_payload : [];
        $collectionId = $payload['marketing_campaign_collection_id'] ?? null;

        if (! is_numeric($collectionId)) {
            return MarketingCampaignTargetResolution::invalid();
        }

        $collection = MarketingCampaignCollection::query()
            ->with([
                'campaign:id,name',
                'orderedItems.laboratoryTest.laboratoryTestCategory:id,name',
            ])
            ->whereKey((int) $collectionId)
            ->where('marketing_campaign_id', $link->marketing_campaign_id)
            ->where('is_active', true)
            ->first();

        if ($collection === null || $collection->laboratory_brand === null) {
            return MarketingCampaignTargetResolution::invalid();
        }

        $brand = $collection->laboratory_brand;
        $products = $collection->orderedItems
            ->map(function ($item) use ($brand, $allowedQuery) {
                $test = $item->laboratoryTest;

                if (! $test instanceof LaboratoryTest) {
                    return null;
                }

                if ($test->brand !== $brand) {
                    return null;
                }

                return [
                    'id' => $test->id,
                    'name' => $test->name,
                    'other_name' => $test->other_name,
                    'category' => $test->laboratoryTestCategory?->name,
                    'requires_appointment' => (bool) $test->requires_appointment,
                    'formatted_famedic_price' => $test->formatted_famedic_price,
                    'formatted_public_price' => $test->formatted_public_price,
                    'famedic_price_cents' => $test->famedic_price_cents,
                    'public_price_cents' => $test->public_price_cents,
                    'product_url' => route('laboratory-tests.test', [
                        'laboratory_test' => $test->id,
                        ...$allowedQuery,
                    ]),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return MarketingCampaignTargetResolution::inertia('MarketingCampaigns/Collection', [
            'campaign_name' => $collection->campaign?->name,
            'public_title' => $collection->public_title,
            'public_description' => $collection->public_description,
            'brand' => [
                'value' => $brand->value,
                'label' => $brand->label(),
            ],
            'products' => $products,
            'catalog_url' => route('laboratory-tests', [
                'laboratory_brand' => $brand->value,
                ...$allowedQuery,
            ]),
            'brand_selection_url' => route('laboratory-brand-selection', $allowedQuery),
            'add_all_available' => false,
        ]);
    }
}
