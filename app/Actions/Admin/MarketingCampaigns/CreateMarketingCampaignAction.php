<?php

namespace App\Actions\Admin\MarketingCampaigns;

use App\Models\Administrator;
use App\Models\MarketingCampaign;

class CreateMarketingCampaignAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(array $data, Administrator $administrator): MarketingCampaign
    {
        return MarketingCampaign::query()->create(array_merge($data, [
            'created_by' => $administrator->id,
            'updated_by' => $administrator->id,
        ]));
    }
}
