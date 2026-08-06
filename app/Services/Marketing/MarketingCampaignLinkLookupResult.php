<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaignLink;

readonly class MarketingCampaignLinkLookupResult
{
    public function __construct(
        public string $requestedSlug,
        public ?MarketingCampaignLink $link,
        public ?string $canonicalSlug,
        public bool $wasAlias,
    ) {}

    public function found(): bool
    {
        return $this->link !== null;
    }
}
