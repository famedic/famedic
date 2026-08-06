<?php

namespace Database\Factories;

use App\Enums\LaboratoryBrand;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignCollection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketingCampaignCollection>
 */
class MarketingCampaignCollectionFactory extends Factory
{
    protected $model = MarketingCampaignCollection::class;

    public function definition(): array
    {
        return [
            'marketing_campaign_id' => MarketingCampaign::factory(),
            'name' => fake()->sentence(3),
            'public_title' => fake()->sentence(4),
            'public_description' => fake()->optional()->paragraph(),
            'laboratory_brand' => LaboratoryBrand::OLAB,
            'is_active' => true,
        ];
    }
}
