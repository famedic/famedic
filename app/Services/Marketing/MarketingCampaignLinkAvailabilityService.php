<?php

namespace App\Services\Marketing;

use App\Enums\MarketingCampaignLinkPublicAvailability;
use App\Enums\MarketingCampaignLinkStatus;
use App\Enums\MarketingCampaignStatus;
use App\Enums\MarketingCampaignTargetType;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignCollection;
use App\Models\MarketingCampaignLink;
use Carbon\CarbonInterface;

class MarketingCampaignLinkAvailabilityService
{
    public function evaluate(MarketingCampaignLink $link): MarketingCampaignLinkPublicAvailability
    {
        if ($link->trashed()) {
            return MarketingCampaignLinkPublicAvailability::NotFound;
        }

        $campaign = $link->campaign;

        if (! $campaign instanceof MarketingCampaign || $campaign->trashed()) {
            return MarketingCampaignLinkPublicAvailability::NotFound;
        }

        $campaignAvailability = $this->evaluateCampaign($campaign);
        if ($campaignAvailability !== MarketingCampaignLinkPublicAvailability::Available) {
            return $campaignAvailability;
        }

        $linkAvailability = $this->evaluateLinkStatusAndWindow($link);
        if ($linkAvailability !== MarketingCampaignLinkPublicAvailability::Available) {
            return $linkAvailability;
        }

        if (! $this->hasValidTarget($link)) {
            return MarketingCampaignLinkPublicAvailability::InvalidTarget;
        }

        return MarketingCampaignLinkPublicAvailability::Available;
    }

    private function evaluateCampaign(MarketingCampaign $campaign): MarketingCampaignLinkPublicAvailability
    {
        return match ($campaign->status) {
            MarketingCampaignStatus::Draft => MarketingCampaignLinkPublicAvailability::NotFound,
            MarketingCampaignStatus::Paused => MarketingCampaignLinkPublicAvailability::Paused,
            MarketingCampaignStatus::Archived => MarketingCampaignLinkPublicAvailability::Archived,
            MarketingCampaignStatus::Finished => MarketingCampaignLinkPublicAvailability::Expired,
            MarketingCampaignStatus::Scheduled,
            MarketingCampaignStatus::Active => $this->evaluateWindow(
                $campaign->starts_at,
                $campaign->ends_at,
            ),
            default => MarketingCampaignLinkPublicAvailability::NotFound,
        };
    }

    private function evaluateLinkStatusAndWindow(MarketingCampaignLink $link): MarketingCampaignLinkPublicAvailability
    {
        return match ($link->status) {
            MarketingCampaignLinkStatus::Draft => MarketingCampaignLinkPublicAvailability::NotFound,
            MarketingCampaignLinkStatus::Paused => MarketingCampaignLinkPublicAvailability::Paused,
            MarketingCampaignLinkStatus::Archived => MarketingCampaignLinkPublicAvailability::Archived,
            MarketingCampaignLinkStatus::Active => $this->evaluateWindow(
                $link->starts_at,
                $link->ends_at,
            ),
            default => MarketingCampaignLinkPublicAvailability::NotFound,
        };
    }

    private function evaluateWindow(
        ?CarbonInterface $startsAt,
        ?CarbonInterface $endsAt,
    ): MarketingCampaignLinkPublicAvailability {
        if ($startsAt !== null && $startsAt->isFuture()) {
            return MarketingCampaignLinkPublicAvailability::NotStarted;
        }

        if ($endsAt !== null && $endsAt->isPast()) {
            return MarketingCampaignLinkPublicAvailability::Expired;
        }

        return MarketingCampaignLinkPublicAvailability::Available;
    }

    private function hasValidTarget(MarketingCampaignLink $link): bool
    {
        $type = $link->target_type;
        $payload = is_array($link->target_payload) ? $link->target_payload : [];

        if (! $type instanceof MarketingCampaignTargetType) {
            return false;
        }

        return match ($type) {
            MarketingCampaignTargetType::Brand => $this->validBrand($payload),
            MarketingCampaignTargetType::Category => $this->validCategory($payload),
            MarketingCampaignTargetType::Product => $this->validProduct($payload),
            MarketingCampaignTargetType::Collection => $this->validCollection($link, $payload),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validBrand(array $payload): bool
    {
        $brand = $payload['brand'] ?? null;

        return is_string($brand) && \App\Enums\LaboratoryBrand::tryFrom($brand) !== null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validCategory(array $payload): bool
    {
        if (! $this->validBrand($payload)) {
            return false;
        }

        $categoryId = $payload['laboratory_test_category_id'] ?? null;
        if (! is_numeric($categoryId)) {
            return false;
        }

        return LaboratoryTestCategory::query()
            ->whereKey((int) $categoryId)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validProduct(array $payload): bool
    {
        $testId = $payload['laboratory_test_id'] ?? null;
        if (! is_numeric($testId)) {
            return false;
        }

        return LaboratoryTest::query()
            ->whereKey((int) $testId)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validCollection(MarketingCampaignLink $link, array $payload): bool
    {
        $collectionId = $payload['marketing_campaign_collection_id'] ?? null;
        if (! is_numeric($collectionId)) {
            return false;
        }

        $collection = MarketingCampaignCollection::query()
            ->whereKey((int) $collectionId)
            ->where('marketing_campaign_id', $link->marketing_campaign_id)
            ->where('is_active', true)
            ->first();

        return $collection !== null;
    }
}
