<?php

namespace App\Services\Marketing\Targets;

use App\Enums\LaboratoryBrand;
use App\Enums\MarketingCampaignTargetType;
use App\Models\LaboratoryTest;
use App\Models\MarketingCampaignLink;
use App\Services\Marketing\MarketingCampaignBrandPresenter;

class BrandCampaignTargetResolver implements MarketingCampaignTargetResolver
{
    private const PRODUCT_LIMIT = 6;

    public function __construct(
        private readonly MarketingCampaignLandingProductMapper $productMapper,
        private readonly MarketingCampaignBrandPresenter $brandPresenter,
    ) {}

    public function supports(MarketingCampaignTargetType $type): bool
    {
        return $type === MarketingCampaignTargetType::Brand;
    }

    public function resolve(MarketingCampaignLink $link, array $allowedQuery): MarketingCampaignTargetResolution
    {
        $brandValue = $link->target_payload['brand'] ?? null;
        $brand = is_string($brandValue) ? LaboratoryBrand::tryFrom($brandValue) : null;

        if ($brand === null) {
            return MarketingCampaignTargetResolution::invalid();
        }

        $products = LaboratoryTest::query()
            ->with('laboratoryTestCategory:id,name')
            ->where('brand', $brand->value)
            ->orderBy('name')
            ->limit(self::PRODUCT_LIMIT)
            ->get()
            ->map(fn (LaboratoryTest $test) => $this->productMapper->map($test, $allowedQuery))
            ->values()
            ->all();

        $catalogUrl = route('laboratory-tests', [
            'laboratory_brand' => $brand->value,
            ...$allowedQuery,
        ]);

        return MarketingCampaignTargetResolution::resolved(new MarketingCampaignResolvedTarget(
            type: MarketingCampaignTargetType::Brand,
            brand: $this->brandPresenter->present($brand),
            category: null,
            products: $products,
            primaryDestinationUrl: $catalogUrl,
            secondaryDestinationUrl: route('laboratory-brand-selection', $allowedQuery),
            sourceTitle: $brand->label(),
            sourceDescription: null,
        ));
    }
}
