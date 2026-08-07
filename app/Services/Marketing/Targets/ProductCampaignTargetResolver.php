<?php

namespace App\Services\Marketing\Targets;

use App\Enums\MarketingCampaignTargetType;
use App\Models\LaboratoryTest;
use App\Models\MarketingCampaignLink;
use App\Services\Marketing\MarketingCampaignBrandPresenter;

class ProductCampaignTargetResolver implements MarketingCampaignTargetResolver
{
    public function __construct(
        private readonly MarketingCampaignLandingProductMapper $productMapper,
        private readonly MarketingCampaignBrandPresenter $brandPresenter,
    ) {}

    public function supports(MarketingCampaignTargetType $type): bool
    {
        return $type === MarketingCampaignTargetType::Product;
    }

    public function resolve(MarketingCampaignLink $link, array $allowedQuery): MarketingCampaignTargetResolution
    {
        $payload = is_array($link->target_payload) ? $link->target_payload : [];
        $testId = $payload['laboratory_test_id'] ?? null;

        if (! is_numeric($testId)) {
            return MarketingCampaignTargetResolution::invalid();
        }

        $test = LaboratoryTest::query()
            ->with('laboratoryTestCategory:id,name')
            ->find((int) $testId);

        if ($test === null || $test->brand === null) {
            return MarketingCampaignTargetResolution::invalid();
        }

        $brand = $test->brand;
        $product = $this->productMapper->map($test, $allowedQuery);
        $detailUrl = $product['detail_url'];

        $description = $test->description
            ?: $test->common_use
            ?: $test->indications;

        return MarketingCampaignTargetResolution::resolved(new MarketingCampaignResolvedTarget(
            type: MarketingCampaignTargetType::Product,
            brand: $this->brandPresenter->present($brand),
            category: $test->laboratoryTestCategory
                ? ['name' => $test->laboratoryTestCategory->name]
                : null,
            products: [$product],
            primaryDestinationUrl: $detailUrl,
            secondaryDestinationUrl: route('laboratory-tests', [
                'laboratory_brand' => $brand->value,
                ...$allowedQuery,
            ]),
            sourceTitle: $test->name,
            sourceDescription: $description ? (string) $description : null,
        ));
    }
}
