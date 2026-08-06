<?php

namespace App\Actions\Admin\MarketingCampaigns;

use App\Models\Administrator;
use App\Models\MarketingCampaign;

class UpdateMarketingCampaignAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(
        MarketingCampaign $campaign,
        array $data,
        Administrator $administrator,
    ): MarketingCampaign {
        $campaign->update(array_merge($data, [
            'updated_by' => $administrator->id,
        ]));

        return $campaign->refresh();
    }
}
