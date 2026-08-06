<?php

namespace App\Http\Controllers\Marketing;

use App\Enums\MarketingCampaignLinkPublicAvailability;
use App\Http\Controllers\Controller;
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
    ): Response {
        $lookup = $lookupService->find($slug);

        if (! $lookup->found()) {
            abort(404);
        }

        $allowedQuery = $querySanitizer->sanitize($request->query());

        // Alias histórico → 302 al slug canónico (conserva query permitida).
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
            MarketingCampaignLinkPublicAvailability::Available => $this->resolveAvailable(
                $request,
                $link,
                $allowedQuery,
                $targetResolvers,
            ),
        };
    }

    /**
     * @param  array<string, string>  $allowedQuery
     */
    private function resolveAvailable(
        Request $request,
        \App\Models\MarketingCampaignLink $link,
        array $allowedQuery,
        MarketingCampaignTargetResolverRegistry $targetResolvers,
    ): Response {
        $resolution = $targetResolvers->resolve($link, $allowedQuery);

        if ($resolution->isInvalid()) {
            abort(404);
        }

        if ($resolution->isRedirect() && is_string($resolution->url)) {
            return redirect()->to($resolution->url, 302);
        }

        if ($resolution->isInertia() && is_string($resolution->component)) {
            return Inertia::render($resolution->component, $resolution->props)
                ->toResponse($request);
        }

        abort(404);
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
