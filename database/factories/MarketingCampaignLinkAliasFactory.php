<?php

namespace Database\Factories;

use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignLinkAlias;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MarketingCampaignLinkAlias>
 */
class MarketingCampaignLinkAliasFactory extends Factory
{
    protected $model = MarketingCampaignLinkAlias::class;

    public function definition(): array
    {
        return [
            'marketing_campaign_link_id' => MarketingCampaignLink::factory(),
            'slug' => Str::slug(fake()->unique()->words(3, true)).'-'.Str::lower(Str::random(6)),
        ];
    }
}
