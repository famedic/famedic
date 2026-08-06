<?php

namespace App\Services\Marketing\Targets;

use App\Enums\LaboratoryBrand;
use App\Enums\MarketingCampaignTargetType;
use App\Models\MarketingCampaignLink;

class BrandCampaignTargetResolver implements MarketingCampaignTargetResolver
{
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

        $url = route('laboratory-tests', [
            'laboratory_brand' => $brand->value,
            ...$allowedQuery,
        ]);

        return MarketingCampaignTargetResolution::redirect($url);
    }
}
