<?php

namespace App\Http\Controllers\Marketing;

use App\Actions\Marketing\CaptureMarketingCampaignVisitAction;
use App\Enums\MarketingCampaignLinkPublicAvailability;
use App\Http\Controllers\Controller;
use App\Models\MarketingCampaignLink;
use App\Services\Marketing\MarketingCampaignLandingViewModelFactory;
use App\Services\Marketing\MarketingCampaignLinkAvailabilityService;
use App\Services\Marketing\MarketingCampaignLinkLookupService;
use App\Services\Marketing\MarketingCampaignQueryStringSanitizer;
use App\Services\Marketing\Targets\MarketingCampaignTargetResolverRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class MarketingCampaignLinkController extends Controller
{
    public function __invoke(
        Request $request,
        string $slug,
        MarketingCampaignLinkLookupService $lookupService,
        MarketingCampaignLinkAvailabilityService $availabilityService,
        MarketingCampaignQueryStringSanitizer $querySanitizer,
        MarketingCampaignTargetResolverRegistry $targetResolvers,
        MarketingCampaignLandingViewModelFactory $landingFactory,
        CaptureMarketingCampaignVisitAction $captureVisit,
    ): Response {
        $lookup = $lookupService->find($slug);

        if (! $lookup->found()) {
            abort(404);
        }

        $allowedQuery = $querySanitizer->sanitize($request->query());

        if (
            $lookup->wasAlias
            && $lookup->canonicalSlug !== null
            && $lookup->canonicalSlug !== $lookup->requestedSlug
        ) {
            return redirect()->route(
                'campaign-links.show',
                ['slug' => $lookup->canonicalSlug, ...$allowedQuery],
                302,
            );
        }

        $link = $lookup->link;
        $availability = $availabilityService->evaluate($link);

        return match ($availability) {
            MarketingCampaignLinkPublicAvailability::NotFound,
            MarketingCampaignLinkPublicAvailability::InvalidTarget => abort(404),
            MarketingCampaignLinkPublicAvailability::NotStarted => Inertia::render(
                'MarketingCampaigns/Upcoming',
                $this->statusPageProps(),
            )->toResponse($request),
            MarketingCampaignLinkPublicAvailability::Paused => Inertia::render(
                'MarketingCampaigns/Unavailable',
                $this->statusPageProps(),
            )->toResponse($request),
            MarketingCampaignLinkPublicAvailability::Expired,
            MarketingCampaignLinkPublicAvailability::Archived => Inertia::render(
                'MarketingCampaigns/Expired',
                $this->statusPageProps(),
            )->toResponse($request),
            MarketingCampaignLinkPublicAvailability::Available => $this->renderLanding(
                $request,
                $link,
                $allowedQuery,
                $targetResolvers,
                $landingFactory,
                $captureVisit,
            ),
        };
    }

    /**
     * @param  array<string, string>  $allowedQuery
     */
    private function renderLanding(
        Request $request,
        MarketingCampaignLink $link,
        array $allowedQuery,
        MarketingCampaignTargetResolverRegistry $targetResolvers,
        MarketingCampaignLandingViewModelFactory $landingFactory,
        CaptureMarketingCampaignVisitAction $captureVisit,
    ): Response {
        $resolution = $targetResolvers->resolve($link, $allowedQuery);

        if ($resolution->isInvalid() || ! $resolution->isResolved() || $resolution->target === null) {
            abort(404);
        }

        $campaign = $link->campaign;
        if ($campaign === null) {
            abort(404);
        }

        $viewModel = $landingFactory->make(
            $campaign,
            $link,
            $resolution->target,
            $allowedQuery,
        );

        $response = Inertia::render('MarketingCampaigns/Landing', $viewModel)
            ->toResponse($request);

        if (config('marketing-attribution.enabled', true)) {
            try {
                $capture = $captureVisit($link, $request, $allowedQuery, now());

                if ($capture->cookie !== null) {
                    $response->headers->setCookie($capture->cookie);
                }
            } catch (\Throwable $exception) {
                Log::error('marketing_campaign_visit_capture_failed', [
                    'marketing_campaign_id' => $campaign->id,
                    'marketing_campaign_link_id' => $link->id,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $response;
    }

    /**
     * @return array{catalog_url: string}
     */
    private function statusPageProps(): array
    {
        return [
            'catalog_url' => route('laboratory-brand-selection'),
        ];
    }
}
