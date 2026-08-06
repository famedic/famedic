<?php

namespace Database\Factories;

use App\Enums\LaboratoryBrand;
use App\Enums\MarketingCampaignLinkStatus;
use App\Enums\MarketingCampaignTargetType;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignLink;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MarketingCampaignLink>
 */
class MarketingCampaignLinkFactory extends Factory
{
    protected $model = MarketingCampaignLink::class;

    public function definition(): array
    {
        return [
            'marketing_campaign_id' => MarketingCampaign::factory(),
            'name' => fake()->sentence(3),
            'slug' => Str::slug(fake()->unique()->words(3, true)).'-'.Str::lower(Str::random(6)),
            'status' => MarketingCampaignLinkStatus::Draft,
            'target_type' => MarketingCampaignTargetType::Brand,
            'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
            'starts_at' => null,
            'ends_at' => null,
        ];
    }
}
