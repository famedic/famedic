<?php

namespace Database\Factories;

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignAttribution;
use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignVisit;
use App\Services\Marketing\MarketingCampaignAttributionTokenService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketingCampaignAttribution>
 */
class MarketingCampaignAttributionFactory extends Factory
{
    protected $model = MarketingCampaignAttribution::class;

    public function definition(): array
    {
        $tokenHash = app(MarketingCampaignAttributionTokenService::class)
            ->hash($this->faker->uuid());

        $touchedAt = now()->subDay();

        return [
            'visitor_token_hash' => $tokenHash,
            'first_campaign_id' => MarketingCampaign::factory(),
            'first_link_id' => MarketingCampaignLink::factory(),
            'last_campaign_id' => MarketingCampaign::factory(),
            'last_link_id' => MarketingCampaignLink::factory(),
            'first_touched_at' => $touchedAt,
            'last_touched_at' => $touchedAt,
            'expires_at' => $touchedAt->copy()->addDays(30),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'first_touched_at' => now()->subDays(40),
            'last_touched_at' => now()->subDays(40),
            'expires_at' => now()->subDays(10),
        ]);
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'first_touched_at' => now()->subDay(),
            'last_touched_at' => now()->subDay(),
            'expires_at' => now()->addDays(30),
        ]);
    }

    public function withVisits(): static
    {
        return $this->afterCreating(function (MarketingCampaignAttribution $attribution) {
            if ($attribution->first_visit_id !== null) {
                return;
            }

            $visit = MarketingCampaignVisit::factory()->create([
                'marketing_campaign_id' => $attribution->first_campaign_id,
                'marketing_campaign_link_id' => $attribution->first_link_id,
                'marketing_campaign_attribution_id' => $attribution->id,
                'visitor_token_hash' => $attribution->visitor_token_hash,
                'visited_at' => $attribution->first_touched_at,
                'created_at' => $attribution->first_touched_at,
            ]);

            $attribution->update([
                'first_visit_id' => $visit->id,
                'last_visit_id' => $visit->id,
                'last_campaign_id' => $attribution->first_campaign_id,
                'last_link_id' => $attribution->first_link_id,
            ]);
        });
    }

    public function withDistinctFirstAndLast(MarketingCampaignVisit $first, MarketingCampaignVisit $last): static
    {
        return $this->state(fn () => [
            'first_visit_id' => $first->id,
            'last_visit_id' => $last->id,
            'first_campaign_id' => $first->marketing_campaign_id,
            'first_link_id' => $first->marketing_campaign_link_id,
            'last_campaign_id' => $last->marketing_campaign_id,
            'last_link_id' => $last->marketing_campaign_link_id,
            'first_touched_at' => $first->visited_at,
            'last_touched_at' => $last->visited_at,
            'visitor_token_hash' => $first->visitor_token_hash,
        ]);
    }
}
