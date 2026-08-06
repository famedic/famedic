<?php

namespace App\Services\Marketing\Targets;

use App\Enums\MarketingCampaignTargetType;
use App\Models\MarketingCampaignLink;

interface MarketingCampaignTargetResolver
{
    public function supports(MarketingCampaignTargetType $type): bool;

    /**
     * @param  array<string, string>  $allowedQuery
     */
    public function resolve(MarketingCampaignLink $link, array $allowedQuery): MarketingCampaignTargetResolution;
}
