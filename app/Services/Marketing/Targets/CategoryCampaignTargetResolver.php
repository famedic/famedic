<?php

namespace App\Services\Marketing\Targets;

use App\Enums\LaboratoryBrand;
use App\Enums\MarketingCampaignTargetType;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Models\MarketingCampaignLink;
use App\Services\Marketing\MarketingCampaignBrandPresenter;

class CategoryCampaignTargetResolver implements MarketingCampaignTargetResolver
{
    private const PRODUCT_LIMIT = 12;

    public function __construct(
        private readonly MarketingCampaignLandingProductMapper $productMapper,
        private readonly MarketingCampaignBrandPresenter $brandPresenter,
    ) {}

    public function supports(MarketingCampaignTargetType $type): bool
    {
        return $type === MarketingCampaignTargetType::Category;
    }

    public function resolve(MarketingCampaignLink $link, array $allowedQuery): MarketingCampaignTargetResolution
    {
        $payload = is_array($link->target_payload) ? $link->target_payload : [];
        $brandValue = $payload['brand'] ?? null;
        $brand = is_string($brandValue) ? LaboratoryBrand::tryFrom($brandValue) : null;
        $categoryId = $payload['laboratory_test_category_id'] ?? null;

        if ($brand === null || ! is_numeric($categoryId)) {
            return MarketingCampaignTargetResolution::invalid();
        }

        $category = LaboratoryTestCategory::query()->find((int) $categoryId);
        if ($category === null) {
            return MarketingCampaignTargetResolution::invalid();
        }

        $products = LaboratoryTest::query()
            ->with('laboratoryTestCategory:id,name')
            ->where('brand', $brand->value)
            ->where('laboratory_test_category_id', $category->id)
            ->orderBy('name')
            ->limit(self::PRODUCT_LIMIT)
            ->get()
            ->map(fn (LaboratoryTest $test) => $this->productMapper->map($test, $allowedQuery))
            ->values()
            ->all();

        $catalogUrl = route('laboratory-tests', [
            'laboratory_brand' => $brand->value,
            'category' => $category->name,
            ...$allowedQuery,
        ]);

        return MarketingCampaignTargetResolution::resolved(new MarketingCampaignResolvedTarget(
            type: MarketingCampaignTargetType::Category,
            brand: $this->brandPresenter->present($brand),
            category: ['name' => $category->name],
            products: $products,
            primaryDestinationUrl: $catalogUrl,
            secondaryDestinationUrl: route('laboratory-tests', [
                'laboratory_brand' => $brand->value,
                ...$allowedQuery,
            ]),
            sourceTitle: $category->name,
            sourceDescription: null,
        ));
    }
}
