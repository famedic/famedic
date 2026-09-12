<?php

namespace App\Services\Marketing;

use App\Enums\MarketingCampaignTargetType;
use App\Models\MarketingCampaignCollection;
use App\Models\MarketingCampaignLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

class MarketingCampaignCollectionLinkResolver
{
    public function countForCollection(MarketingCampaignCollection $collection): int
    {
        return $this->baseQuery($collection)->count();
    }

    /**
     * @return Collection<int, MarketingCampaignLink>
     */
    public function linksForCollection(
        MarketingCampaignCollection $collection,
        int $limit = 20,
    ): Collection {
        return $this->baseQuery($collection)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['id', 'name', 'slug', 'status', 'updated_at']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function linkPayloads(MarketingCampaignCollection $collection, int $limit = 20): array
    {
        return $this->linksForCollection($collection, $limit)
            ->map(fn (MarketingCampaignLink $link) => [
                'id' => $link->id,
                'name' => $link->name,
                'slug' => $link->slug,
                'public_url' => URL::to('/c/'.$link->slug),
                'status' => $link->status?->value ?? $link->status,
                'status_label' => $link->status?->label(),
                'updated_at' => $link->updated_at,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $collectionIds
     * @return array<int, int>
     */
    public function countsForCampaign(int $campaignId, array $collectionIds): array
    {
        if ($collectionIds === []) {
            return [];
        }

        $counts = [];

        foreach ($collectionIds as $collectionId) {
            $counts[(int) $collectionId] = 0;
        }

        MarketingCampaignLink::query()
            ->where('marketing_campaign_id', $campaignId)
            ->where('target_type', MarketingCampaignTargetType::Collection)
            ->get(['target_payload'])
            ->each(function (MarketingCampaignLink $link) use (&$counts) {
                $collectionId = (int) ($link->target_payload['marketing_campaign_collection_id'] ?? 0);

                if ($collectionId > 0 && array_key_exists($collectionId, $counts)) {
                    $counts[$collectionId]++;
                }
            });

        return $counts;
    }

    private function baseQuery(MarketingCampaignCollection $collection)
    {
        return MarketingCampaignLink::query()
            ->where('marketing_campaign_id', $collection->marketing_campaign_id)
            ->where('target_type', MarketingCampaignTargetType::Collection)
            ->where('target_payload->marketing_campaign_collection_id', $collection->id);
    }
}
