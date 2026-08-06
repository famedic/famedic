<?php

namespace App\Actions\Admin\MarketingCampaigns;

use App\Models\MarketingCampaignCollection;
use App\Services\Marketing\MarketingCampaignCollectionService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateMarketingCampaignCollectionAction
{
    public function __construct(private MarketingCampaignCollectionService $collectionService) {}

    /**
     * Actualiza colección y sincroniza items bajo las mismas invariantes del servicio.
     * Cambio de marca incompatible con los items enviados falla y hace rollback.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(
        MarketingCampaignCollection $collection,
        array $data,
    ): MarketingCampaignCollection {
        $collection->loadMissing('campaign');
        $collection->campaign?->assertWritable();

        return DB::transaction(function () use ($collection, $data) {
            $collection->update(Arr::except($data, ['laboratory_test_ids']));

            return $this->collectionService->syncItems(
                $collection->refresh(),
                $data['laboratory_test_ids'] ?? [],
            );
        });
    }
}
