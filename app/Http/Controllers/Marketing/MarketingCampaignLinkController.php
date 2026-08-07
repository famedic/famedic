<?php

namespace App\Http\Controllers\Marketing;

use App\Enums\MarketingCampaignLinkPublicAvailability;
use App\Http\Controllers\Controller;
use App\Models\MarketingCampaignLink;
use App\Services\Marketing\MarketingCampaignLandingViewModelFactory;
use App\Services\Marketing\MarketingCampaignLinkAvailabilityService;
use App\Services\Marketing\MarketingCampaignLinkLookupService;
use App\Services\Marketing\MarketingCampaignQueryStringSanitizer;
use App\Services\Marketing\Targets\MarketingCampaignTargetResolverRegistry;
use Illuminate\Http\Request;
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

        return Inertia::render('MarketingCampaigns/Landing', $viewModel)
            ->toResponse($request);
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
