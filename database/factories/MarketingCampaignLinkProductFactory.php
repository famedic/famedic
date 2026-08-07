<?php

namespace Database\Factories;

use App\Enums\MarketingCampaignLinkProductSection;
use App\Models\LaboratoryTest;
use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignLinkProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketingCampaignLinkProduct>
 */
class MarketingCampaignLinkProductFactory extends Factory
{
    protected $model = MarketingCampaignLinkProduct::class;

    public function definition(): array
    {
        return [
            'marketing_campaign_link_id' => MarketingCampaignLink::factory(),
            'laboratory_test_id' => LaboratoryTest::factory(),
            'section' => MarketingCampaignLinkProductSection::Primary,
            'position' => 0,
            'is_featured' => true,
        ];
    }

    public function related(): static
    {
        return $this->state(fn () => [
            'section' => MarketingCampaignLinkProductSection::Related,
            'is_featured' => false,
        ]);
    }
}
