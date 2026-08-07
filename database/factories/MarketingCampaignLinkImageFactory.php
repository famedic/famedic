<?php

namespace Database\Factories;

use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignLinkImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketingCampaignLinkImage>
 */
class MarketingCampaignLinkImageFactory extends Factory
{
    protected $model = MarketingCampaignLinkImage::class;

    public function definition(): array
    {
        return [
            'marketing_campaign_link_id' => MarketingCampaignLink::factory(),
            'type' => 'gallery',
            'source' => 'external',
            'disk' => null,
            'path' => null,
            'external_url' => 'https://cdn.example.com/'.fake()->uuid().'.jpg',
            'alt_text' => fake()->sentence(3),
            'position' => 0,
        ];
    }
}
