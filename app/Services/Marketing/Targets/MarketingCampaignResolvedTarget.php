<?php

namespace App\Services\Marketing\Targets;

use App\Enums\MarketingCampaignTargetType;

readonly class MarketingCampaignResolvedTarget
{
    /**
     * @param  array{value: string, label: string, logo_url: string, imageSrc: string, states: list<string>}|null  $brand
     * @param  array{name: string}|null  $category
     * @param  list<array<string, mixed>>  $products
     */
    public function __construct(
        public MarketingCampaignTargetType $type,
        public ?array $brand,
        public ?array $category,
        public array $products,
        public string $primaryDestinationUrl,
        public ?string $secondaryDestinationUrl,
        public ?string $sourceTitle = null,
        public ?string $sourceDescription = null,
    ) {}
}
