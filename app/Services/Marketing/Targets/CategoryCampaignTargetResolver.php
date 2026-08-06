<?php

namespace App\Services\Marketing\Targets;

use App\Enums\LaboratoryBrand;
use App\Enums\MarketingCampaignTargetType;
use App\Models\LaboratoryTestCategory;
use App\Models\MarketingCampaignLink;

class CategoryCampaignTargetResolver implements MarketingCampaignTargetResolver
{
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

        $url = route('laboratory-tests', [
            'laboratory_brand' => $brand->value,
            'category' => $category->name,
            ...$allowedQuery,
        ]);

        return MarketingCampaignTargetResolution::redirect($url);
    }
}
