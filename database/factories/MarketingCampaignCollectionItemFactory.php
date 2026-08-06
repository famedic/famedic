<?php

namespace Database\Factories;

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryTest;
use App\Models\MarketingCampaignCollection;
use App\Models\MarketingCampaignCollectionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketingCampaignCollectionItem>
 */
class MarketingCampaignCollectionItemFactory extends Factory
{
    protected $model = MarketingCampaignCollectionItem::class;

    public function definition(): array
    {
        return [
            'marketing_campaign_collection_id' => MarketingCampaignCollection::factory(),
            'laboratory_test_id' => LaboratoryTest::factory()->state([
                'brand' => LaboratoryBrand::OLAB,
            ]),
            'position' => 0,
        ];
    }
}
