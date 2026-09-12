<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\MarketingCampaigns\ArchiveMarketingCampaignAction;
use App\Http\Controllers\Controller;
use App\Models\MarketingCampaign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ArchiveMarketingCampaignController extends Controller
{
    public function __invoke(
        Request $request,
        MarketingCampaign $marketingCampaign,
        ArchiveMarketingCampaignAction $action,
    ): RedirectResponse {
        $this->authorize('archive', $marketingCampaign);

        $action($marketingCampaign, $request->user()->administrator);

        return redirect()
            ->route('admin.marketing-campaigns.show', $marketingCampaign)
            ->flashMessage('Campaña archivada.');
    }
}
