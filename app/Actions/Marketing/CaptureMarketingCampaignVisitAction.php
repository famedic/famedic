<?php

namespace App\Actions\Marketing;

use App\DataTransferObjects\Marketing\MarketingCampaignEffectiveTrackingData;
use App\Models\MarketingCampaignAttribution;
use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignVisit;
use App\Services\Marketing\MarketingCampaignAttributionCookieFactory;
use App\Services\Marketing\MarketingCampaignAttributionTokenService;
use App\Services\Marketing\MarketingCampaignReferrerHostExtractor;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;

readonly class CaptureMarketingCampaignVisitResult
{
    public function __construct(
        public ?MarketingCampaignVisit $visit,
        public ?Cookie $cookie,
        public ?string $visitorTokenHash = null,
    ) {}
}

class CaptureMarketingCampaignVisitAction
{
    public function __construct(
        private MarketingCampaignAttributionTokenService $tokenService,
        private MarketingCampaignAttributionCookieFactory $cookieFactory,
        private MarketingCampaignReferrerHostExtractor $referrerExtractor,
    ) {}

    /**
     * @param  array<string, string>  $allowedQuery
     */
    public function __invoke(
        MarketingCampaignLink $link,
        Request $request,
        array $allowedQuery,
        CarbonInterface $visitedAt,
    ): CaptureMarketingCampaignVisitResult {
        if (! config('marketing-attribution.enabled', true)) {
            return new CaptureMarketingCampaignVisitResult(null, null);
        }

        $link->loadMissing('campaign');
        $campaign = $link->campaign;

        if ($campaign === null) {
            return new CaptureMarketingCampaignVisitResult(null, null);
        }

        $tracking = MarketingCampaignEffectiveTrackingData::fromLinkAndQuery(
            $link,
            $allowedQuery,
            (int) config('marketing-attribution.limits.utm', 255),
            (int) config('marketing-attribution.limits.gclid', 255),
        );

        $cookieToken = $this->cookieFactory->readToken($request);
        $token = $cookieToken ?? $this->tokenService->generate();
        $tokenHash = $this->tokenService->hash($token);
        $windowDays = (int) config('marketing-attribution.window_days', 30);
        $expiresAt = $visitedAt->copy()->addDays($windowDays);

        $landingPath = '/c/'.$link->slug;
        $limits = config('marketing-attribution.limits', []);
        $landingPathLimit = (int) ($limits['landing_path'] ?? 255);

        if (mb_strlen($landingPath) > $landingPathLimit) {
            $landingPath = mb_substr($landingPath, 0, $landingPathLimit);
        }

        $referrerHost = $this->referrerExtractor->extract(
            $request->headers->get('Referer'),
            (int) ($limits['referrer_host'] ?? 255),
        );

        $userId = $request->user()?->id;
        $customerId = $request->user()?->customer?->id;

        return DB::transaction(function () use (
            $link,
            $campaign,
            $tracking,
            $token,
            $tokenHash,
            $visitedAt,
            $expiresAt,
            $landingPath,
            $referrerHost,
            $userId,
            $customerId,
            $windowDays,
        ) {
            $attribution = MarketingCampaignAttribution::query()
                ->where('visitor_token_hash', $tokenHash)
                ->where('expires_at', '>', $visitedAt)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $isNewCycle = $attribution === null;

            if ($isNewCycle) {
                $attribution = MarketingCampaignAttribution::query()->create([
                    'visitor_token_hash' => $tokenHash,
                    'first_campaign_id' => $campaign->id,
                    'first_link_id' => $link->id,
                    'last_campaign_id' => $campaign->id,
                    'last_link_id' => $link->id,
                    'first_touched_at' => $visitedAt,
                    'last_touched_at' => $visitedAt,
                    'expires_at' => $expiresAt,
                    'user_id' => $userId,
                    'customer_id' => $customerId,
                ]);
            }

            $visit = MarketingCampaignVisit::query()->create(array_merge(
                $tracking->toVisitColumns(),
                [
                    'marketing_campaign_id' => $campaign->id,
                    'marketing_campaign_link_id' => $link->id,
                    'marketing_campaign_attribution_id' => $attribution->id,
                    'visitor_token_hash' => $tokenHash,
                    'user_id' => $userId,
                    'customer_id' => $customerId,
                    'landing_path' => $landingPath,
                    'referrer_host' => $referrerHost,
                    'visited_at' => $visitedAt,
                    'created_at' => $visitedAt,
                ],
            ));

            if ($isNewCycle) {
                $attribution->update([
                    'first_visit_id' => $visit->id,
                    'last_visit_id' => $visit->id,
                ]);
            } else {
                $attribution->update([
                    'last_visit_id' => $visit->id,
                    'last_campaign_id' => $campaign->id,
                    'last_link_id' => $link->id,
                    'last_touched_at' => $visitedAt,
                    'expires_at' => $visitedAt->copy()->addDays($windowDays),
                    'user_id' => $userId ?? $attribution->user_id,
                    'customer_id' => $customerId ?? $attribution->customer_id,
                ]);
            }

            $cookie = $this->cookieFactory->makeCookie($token, $expiresAt);

            return new CaptureMarketingCampaignVisitResult($visit, $cookie, $tokenHash);
        });
    }
}
