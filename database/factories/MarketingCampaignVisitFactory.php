<?php

namespace Database\Factories;

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignAttribution;
use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignVisit;
use App\Services\Marketing\MarketingCampaignAttributionTokenService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketingCampaignVisit>
 */
class MarketingCampaignVisitFactory extends Factory
{
    protected $model = MarketingCampaignVisit::class;

    public function definition(): array
    {
        $visitedAt = now()->subHour();

        return [
            'marketing_campaign_id' => MarketingCampaign::factory(),
            'marketing_campaign_link_id' => MarketingCampaignLink::factory(),
            'marketing_campaign_attribution_id' => null,
            'visitor_token_hash' => app(MarketingCampaignAttributionTokenService::class)
                ->hash($this->faker->uuid()),
            'utm_source' => 'facebook',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'demo',
            'utm_term' => null,
            'utm_content' => null,
            'gclid' => null,
            'fbclid' => null,
            'landing_path' => '/c/demo',
            'referrer_host' => 'facebook.com',
            'visited_at' => $visitedAt,
            'created_at' => $visitedAt,
        ];
    }

    public function forAttribution(MarketingCampaignAttribution $attribution): static
    {
        return $this->state(fn () => [
            'marketing_campaign_attribution_id' => $attribution->id,
            'visitor_token_hash' => $attribution->visitor_token_hash,
            'marketing_campaign_id' => $attribution->last_campaign_id,
            'marketing_campaign_link_id' => $attribution->last_link_id,
        ]);
    }
}
