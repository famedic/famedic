<?php

namespace App\Services\Marketing\Targets;

use App\Enums\MarketingCampaignTargetType;
use App\Models\LaboratoryTest;
use App\Models\MarketingCampaignCollection;
use App\Models\MarketingCampaignLink;
use App\Services\Marketing\MarketingCampaignBrandPresenter;

class CollectionCampaignTargetResolver implements MarketingCampaignTargetResolver
{
    private const PRODUCT_LIMIT = 50;

    public function __construct(
        private readonly MarketingCampaignLandingProductMapper $productMapper,
        private readonly MarketingCampaignBrandPresenter $brandPresenter,
    ) {}

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
            ->with(['orderedItems.laboratoryTest.laboratoryTestCategory:id,name'])
            ->whereKey((int) $collectionId)
            ->where('marketing_campaign_id', $link->marketing_campaign_id)
            ->where('is_active', true)
            ->first();

        if ($collection === null || $collection->laboratory_brand === null) {
            return MarketingCampaignTargetResolution::invalid();
        }

        $brand = $collection->laboratory_brand;

        $products = $collection->orderedItems
            ->take(self::PRODUCT_LIMIT)
            ->map(function ($item) use ($brand, $allowedQuery) {
                $test = $item->laboratoryTest;

                if (! $test instanceof LaboratoryTest || $test->brand !== $brand) {
                    return null;
                }

                return $this->productMapper->map($test, $allowedQuery);
            })
            ->filter()
            ->values()
            ->all();

        $catalogUrl = route('laboratory-tests', [
            'laboratory_brand' => $brand->value,
            ...$allowedQuery,
        ]);

        return MarketingCampaignTargetResolution::resolved(new MarketingCampaignResolvedTarget(
            type: MarketingCampaignTargetType::Collection,
            brand: $this->brandPresenter->present($brand),
            category: null,
            products: $products,
            primaryDestinationUrl: $catalogUrl,
            secondaryDestinationUrl: route('laboratory-brand-selection', $allowedQuery),
            sourceTitle: $collection->public_title,
            sourceDescription: $collection->public_description,
        ));
    }
}
