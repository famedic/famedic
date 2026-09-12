<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignLinkAlias;

class MarketingCampaignLinkLookupService
{
    public function __construct(
        private readonly MarketingCampaignLinkSlugService $slugService,
    ) {}

    public function find(string $slug): MarketingCampaignLinkLookupResult
    {
        $requestedSlug = $this->slugService->normalize($slug);

        if ($requestedSlug === '') {
            return new MarketingCampaignLinkLookupResult(
                requestedSlug: $requestedSlug,
                link: null,
                canonicalSlug: null,
                wasAlias: false,
            );
        }

        $link = MarketingCampaignLink::query()
            ->withTrashed()
            ->with(['campaign' => fn ($query) => $query->withTrashed()])
            ->where('slug', $requestedSlug)
            ->first();

        if ($link) {
            return new MarketingCampaignLinkLookupResult(
                requestedSlug: $requestedSlug,
                link: $link,
                canonicalSlug: $link->slug,
                wasAlias: false,
            );
        }

        $alias = MarketingCampaignLinkAlias::query()
            ->where('slug', $requestedSlug)
            ->first();

        if (! $alias) {
            return new MarketingCampaignLinkLookupResult(
                requestedSlug: $requestedSlug,
                link: null,
                canonicalSlug: null,
                wasAlias: false,
            );
        }

        $aliasedLink = MarketingCampaignLink::query()
            ->withTrashed()
            ->with(['campaign' => fn ($query) => $query->withTrashed()])
            ->find($alias->marketing_campaign_link_id);

        if (! $aliasedLink) {
            return new MarketingCampaignLinkLookupResult(
                requestedSlug: $requestedSlug,
                link: null,
                canonicalSlug: null,
                wasAlias: true,
            );
        }

        return new MarketingCampaignLinkLookupResult(
            requestedSlug: $requestedSlug,
            link: $aliasedLink,
            canonicalSlug: $aliasedLink->slug,
            wasAlias: true,
        );
    }
}
