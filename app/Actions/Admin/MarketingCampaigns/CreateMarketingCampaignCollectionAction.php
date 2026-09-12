<?php

namespace App\Actions\Admin\MarketingCampaigns;

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignCollection;
use App\Services\Marketing\MarketingCampaignCollectionService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateMarketingCampaignCollectionAction
{
    public function __construct(private MarketingCampaignCollectionService $collectionService) {}

    /**
     * Crea una colección. Puede nacer vacía (borrador/configuración).
     * La sincronización de items aplica las invariantes del dominio (no solo HTTP).
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(array $data): MarketingCampaignCollection
    {
        return DB::transaction(function () use ($data) {
            $campaign = MarketingCampaign::query()->findOrFail((int) $data['marketing_campaign_id']);
            $campaign->assertWritable();

            $collection = MarketingCampaignCollection::query()->create(
                Arr::except($data, ['laboratory_test_ids'])
            );

            return $this->collectionService->syncItems(
                $collection,
                $data['laboratory_test_ids'] ?? [],
            );
        });
    }
}
