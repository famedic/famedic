<?php

namespace App\Services\Marketing\Targets;

use App\Enums\MarketingCampaignTargetType;
use App\Models\MarketingCampaignLink;
use Illuminate\Contracts\Container\Container;

class MarketingCampaignTargetResolverRegistry
{
    /**
     * @var list<class-string<MarketingCampaignTargetResolver>>
     */
    private array $resolverClasses = [
        BrandCampaignTargetResolver::class,
        CategoryCampaignTargetResolver::class,
        ProductCampaignTargetResolver::class,
        CollectionCampaignTargetResolver::class,
    ];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @param  array<string, string>  $allowedQuery
     */
    public function resolve(MarketingCampaignLink $link, array $allowedQuery): MarketingCampaignTargetResolution
    {
        $type = $link->target_type;

        if (! $type instanceof MarketingCampaignTargetType) {
            return MarketingCampaignTargetResolution::invalid();
        }

        foreach ($this->resolverClasses as $resolverClass) {
            /** @var MarketingCampaignTargetResolver $resolver */
            $resolver = $this->container->make($resolverClass);

            if ($resolver->supports($type)) {
                return $resolver->resolve($link, $allowedQuery);
            }
        }

        return MarketingCampaignTargetResolution::invalid();
    }
}
