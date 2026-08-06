<?php

namespace Database\Factories;

use App\Enums\MarketingCampaignStatus;
use App\Models\MarketingCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketingCampaign>
 */
class MarketingCampaignFactory extends Factory
{
    protected $model = MarketingCampaign::class;

    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'status' => MarketingCampaignStatus::Draft,
            'starts_at' => null,
            'ends_at' => null,
        ];
    }
}
