<?php

namespace App\Services\Marketing;

use App\Enums\LaboratoryBrand;
use App\Enums\MarketingCampaignTargetType;
use App\Models\LaboratoryTest;
use App\Models\MarketingCampaignCollection;
use App\Models\MarketingCampaignLink;

class MarketingCampaignLinkBrandResolver
{
    public function resolve(MarketingCampaignLink $link): ?LaboratoryBrand
    {
        $type = $link->target_type;
        $payload = is_array($link->target_payload) ? $link->target_payload : [];

        if (! $type instanceof MarketingCampaignTargetType) {
            return null;
        }

        return match ($type) {
            MarketingCampaignTargetType::Brand,
            MarketingCampaignTargetType::Category => LaboratoryBrand::tryFrom((string) ($payload['brand'] ?? '')),
            MarketingCampaignTargetType::Product => $this->brandFromProduct($payload),
            MarketingCampaignTargetType::Collection => $this->brandFromCollection($link, $payload),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function brandFromProduct(array $payload): ?LaboratoryBrand
    {
        $testId = $payload['laboratory_test_id'] ?? null;
        if (! is_numeric($testId)) {
            return null;
        }

        $test = LaboratoryTest::query()->find((int) $testId);

        return $test?->brand instanceof LaboratoryBrand ? $test->brand : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function brandFromCollection(MarketingCampaignLink $link, array $payload): ?LaboratoryBrand
    {
        $collectionId = $payload['marketing_campaign_collection_id'] ?? null;
        if (! is_numeric($collectionId)) {
            return null;
        }

        $collection = MarketingCampaignCollection::query()
            ->whereKey((int) $collectionId)
            ->where('marketing_campaign_id', $link->marketing_campaign_id)
            ->first();

        return $collection?->laboratory_brand instanceof LaboratoryBrand
            ? $collection->laboratory_brand
            : null;
    }
}
