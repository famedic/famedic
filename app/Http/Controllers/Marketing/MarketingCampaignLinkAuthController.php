<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Services\Marketing\MarketingCampaignQueryStringSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MarketingCampaignLinkAuthController extends Controller
{
    public function __invoke(
        Request $request,
        string $slug,
        MarketingCampaignQueryStringSanitizer $querySanitizer,
    ): RedirectResponse {
        $allowedQuery = $querySanitizer->sanitize($request->query());

        $request->session()->put(
            'url.intended',
            route('campaign-links.show', ['slug' => $slug, ...$allowedQuery]),
        );

        return redirect()->route('login');
    }
}
