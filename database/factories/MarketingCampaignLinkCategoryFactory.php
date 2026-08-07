<?php

namespace Database\Factories;

use App\Models\LaboratoryTestCategory;
use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignLinkCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketingCampaignLinkCategory>
 */
class MarketingCampaignLinkCategoryFactory extends Factory
{
    protected $model = MarketingCampaignLinkCategory::class;

    public function definition(): array
    {
        return [
            'marketing_campaign_link_id' => MarketingCampaignLink::factory(),
            'laboratory_test_category_id' => LaboratoryTestCategory::factory(),
            'position' => 0,
        ];
    }
}
