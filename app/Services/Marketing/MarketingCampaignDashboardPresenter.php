<?php

namespace App\Services\Marketing;

use App\Enums\MarketingCampaignStatus;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignConversion;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MarketingCampaignDashboardPresenter
{
    public function __construct(
        private readonly MarketingCampaignLinkUrlBuilder $urlBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(MarketingCampaign $campaign, Collection $links): array
    {
        $configuredProducts = $links->sum(
            fn ($link) => (int) ($link->primary_landing_products_count ?? $link->primaryLandingProducts?->count() ?? 0),
        );

        $checklist = $this->checklist($campaign, $links);
        $completed = collect($checklist)->whereIn('status', ['complete', 'na'])->count();
        $total = collect($checklist)->count();

        return [
            'links_count' => (int) ($campaign->links_count ?? $links->count()),
            'collections_count' => (int) ($campaign->collections_count ?? 0),
            'configured_products_count' => $configuredProducts,
            'completeness_percent' => $total > 0 ? (int) round(($completed / $total) * 100) : 0,
            'primary_link' => $this->linkPayload($links->first()),
        ];
    }

    /**
     * @return list<array{key: string, label: string, status: string, detail?: string}>
     */
    public function checklist(MarketingCampaign $campaign, Collection $links): array
    {
        $primaryLink = $links->first();

        return [
            [
                'key' => 'general',
                'label' => 'Información general',
                'status' => filled($campaign->name) ? 'complete' : 'pending',
                'detail' => filled($campaign->description) ? 'Descripción configurada' : 'Agrega contexto interno opcional',
            ],
            [
                'key' => 'link',
                'label' => 'Al menos un enlace',
                'status' => $links->isNotEmpty() ? 'complete' : 'pending',
            ],
            [
                'key' => 'target',
                'label' => 'Destino configurado',
                'status' => $primaryLink && filled($primaryLink->target_type) ? 'complete' : ($links->isEmpty() ? 'na' : 'pending'),
            ],
            [
                'key' => 'products',
                'label' => 'Productos configurados',
                'status' => $this->productsStatus($primaryLink),
            ],
            [
                'key' => 'content',
                'label' => 'Contenido de landing',
                'status' => $primaryLink && filled($primaryLink->public_title) ? 'complete' : ($links->isEmpty() ? 'na' : 'pending'),
            ],
            [
                'key' => 'hero',
                'label' => 'Imagen principal',
                'status' => $primaryLink && $primaryLink->resolvedHeroImageUrl() ? 'complete' : 'recommended',
                'detail' => 'Opcional, mejora la conversión',
            ],
            [
                'key' => 'utms',
                'label' => 'UTMs',
                'status' => $primaryLink && filled($primaryLink->utm_source) && filled($primaryLink->utm_medium)
                    ? 'complete'
                    : ($links->isEmpty() ? 'na' : 'recommended'),
            ],
            [
                'key' => 'active',
                'label' => 'Campaña activa',
                'status' => $campaign->status === MarketingCampaignStatus::Active ? 'complete' : 'pending',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function linkPayload(?object $link): ?array
    {
        if (! $link) {
            return null;
        }

        return [
            'id' => $link->id,
            'name' => $link->name,
            'slug' => $link->slug,
            'public_url' => $this->urlBuilder->baseUrl($link),
            'base_url' => $this->urlBuilder->baseUrl($link),
            'full_url' => $this->urlBuilder->fullUrl($link),
            'utm_parameters' => $this->urlBuilder->utmParameters($link),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function links(Collection $links): array
    {
        return $links->map(fn ($link) => [
            'id' => $link->id,
            'name' => $link->name,
            'slug' => $link->slug,
            'public_url' => $this->urlBuilder->baseUrl($link),
            'base_url' => $this->urlBuilder->baseUrl($link),
            'full_url' => $this->urlBuilder->fullUrl($link),
            'utm_parameters' => $this->urlBuilder->utmParameters($link),
            'channel_label' => $this->channelLabel($link),
            'status' => $link->status?->value ?? $link->status,
            'status_label' => $link->status?->label(),
            'target_type' => $link->target_type?->value ?? $link->target_type,
            'target_type_label' => $link->target_type?->label(),
            'starts_at' => $link->starts_at,
            'ends_at' => $link->ends_at,
            'created_at' => $link->created_at,
        ])->values()->all();
    }

    private function channelLabel(object $link): string
    {
        $source = $link->utm_source;
        $medium = $link->utm_medium;

        if (($source === null || $source === '') && ($medium === null || $medium === '')) {
            return 'Sin preset';
        }

        return collect([$source, $medium])
            ->reject(fn ($value) => $value === null || $value === '')
            ->implode(' / ');
    }

    /**
     * @param  array{from?: string, to?: string, link_id?: string, utm_source?: string, utm_medium?: string}  $filters
     * @return array<string, mixed>
     */
    public function analytics(MarketingCampaign $campaign, Collection $links, array $filters): array
    {
        $period = $this->periodBounds($filters);
        $linkId = filled($filters['link_id'] ?? '') ? (int) $filters['link_id'] : null;

        $visits = $this->visitBaseQuery($campaign->id, $period, $linkId, $filters);
        $registrations = $this->registrationBaseQuery($campaign->id, $period, $linkId, $filters);
        $lastTouchConversions = $this->conversionBaseQuery('last_campaign_id', $campaign->id, $period, $linkId, $filters, 'last_link_id');
        $firstTouchConversions = $this->conversionBaseQuery('first_campaign_id', $campaign->id, $period, $linkId, $filters, 'first_link_id');

        $visitTotals = (clone $visits)
            ->selectRaw('COUNT(*) as visits_count')
            ->selectRaw("COUNT(DISTINCT {$this->visitVisitorIdentifierColumn()}) as visitors_count")
            ->first();
        $registrationCount = (int) (clone $registrations)->count();
        $conversionTotals = (clone $lastTouchConversions)
            ->selectRaw('COUNT(*) as purchases_count')
            ->selectRaw('COUNT(DISTINCT customer_id) as buyers_count')
            ->selectRaw('COALESCE(SUM(amount_cents), 0) as revenue_cents')
            ->first();
        $firstTouchTotals = (clone $firstTouchConversions)
            ->selectRaw('COUNT(*) as purchases_count')
            ->selectRaw('COUNT(DISTINCT customer_id) as buyers_count')
            ->selectRaw('COALESCE(SUM(amount_cents), 0) as revenue_cents')
            ->first();

        $visitsCount = (int) ($visitTotals->visits_count ?? 0);
        $visitorsCount = (int) ($visitTotals->visitors_count ?? 0);
        $purchasesCount = (int) ($conversionTotals->purchases_count ?? 0);
        $buyersCount = (int) ($conversionTotals->buyers_count ?? 0);
        $revenueCents = (int) ($conversionTotals->revenue_cents ?? 0);

        return [
            'period' => [
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
                'timezone' => config('app.timezone'),
            ],
            'filters' => [
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
                'link_id' => $filters['link_id'] ?? '',
                'utm_source' => $filters['utm_source'] ?? '',
                'utm_medium' => $filters['utm_medium'] ?? '',
            ],
            'totals' => [
                'visits' => $visitsCount,
                'unique_visitors' => $visitorsCount,
                'registrations' => $registrationCount,
                'buyers' => $buyersCount,
                'purchases' => $purchasesCount,
                'revenue_cents' => $revenueCents,
                'average_ticket_cents' => $purchasesCount > 0 ? (int) round($revenueCents / $purchasesCount) : null,
                'visit_to_registration_rate' => $this->rate($registrationCount, $visitorsCount),
                'visit_to_purchase_rate' => $this->rate($purchasesCount, $visitorsCount),
                'registration_to_purchase_rate' => $this->rate($purchasesCount, $registrationCount),
            ],
            'first_touch' => [
                'purchases' => (int) ($firstTouchTotals->purchases_count ?? 0),
                'buyers' => (int) ($firstTouchTotals->buyers_count ?? 0),
                'revenue_cents' => (int) ($firstTouchTotals->revenue_cents ?? 0),
            ],
            'links' => $this->linkBreakdown($links, $campaign->id, $period, $filters),
            'utm_breakdown' => $this->utmBreakdown($campaign->id, $period, $linkId, $filters),
        ];
    }

    private function productsStatus(?object $link): string
    {
        if (! $link) {
            return 'na';
        }

        $count = (int) ($link->primary_landing_products_count ?? $link->primaryLandingProducts?->count() ?? 0);

        if ($count > 0) {
            return 'complete';
        }

        return filled($link->target_type) ? 'recommended' : 'pending';
    }

    /**
     * @param  array<string, string>  $filters
     * @return array{from: Carbon|null, to: Carbon|null}
     */
    private function periodBounds(array $filters): array
    {
        $timezone = config('app.timezone');

        return [
            'from' => filled($filters['from'] ?? '') ? Carbon::parse($filters['from'], $timezone)->startOfDay()->utc() : null,
            'to' => filled($filters['to'] ?? '') ? Carbon::parse($filters['to'], $timezone)->endOfDay()->utc() : null,
        ];
    }

    /**
     * @param  array{from: Carbon|null, to: Carbon|null}  $period
     * @param  array<string, string>  $filters
     */
    private function visitBaseQuery(int $campaignId, array $period, ?int $linkId, array $filters): Builder
    {
        $query = DB::table('marketing_campaign_visits')
            ->where('marketing_campaign_id', $campaignId);

        $this->applyDate($query, 'visited_at', $period);

        if ($linkId !== null) {
            $query->where('marketing_campaign_link_id', $linkId);
        }

        return $this->applyUtm($query, 'utm_source', 'utm_medium', $filters);
    }

    /**
     * @param  array{from: Carbon|null, to: Carbon|null}  $period
     * @param  array<string, string>  $filters
     */
    private function registrationBaseQuery(int $campaignId, array $period, ?int $linkId, array $filters): Builder
    {
        $query = DB::table('marketing_campaign_attributions')
            ->leftJoin('marketing_campaign_visits as identified_visit', 'identified_visit.id', '=', 'marketing_campaign_attributions.identified_visit_id')
            ->where('marketing_campaign_attributions.identified_campaign_id', $campaignId)
            ->whereNotNull('marketing_campaign_attributions.customer_id')
            ->whereNotNull('marketing_campaign_attributions.identified_at');

        $this->applyDate($query, 'marketing_campaign_attributions.identified_at', $period);

        if ($linkId !== null) {
            $query->where('marketing_campaign_attributions.identified_link_id', $linkId);
        }

        return $this->applyUtm($query, 'identified_visit.utm_source', 'identified_visit.utm_medium', $filters);
    }

    /**
     * @param  array{from: Carbon|null, to: Carbon|null}  $period
     * @param  array<string, string>  $filters
     */
    private function conversionBaseQuery(
        string $campaignColumn,
        int $campaignId,
        array $period,
        ?int $linkId,
        array $filters,
        string $linkColumn,
    ): Builder {
        $query = DB::table('marketing_campaign_conversions')
            ->where('conversion_type', MarketingCampaignConversion::TYPE_LABORATORY_PURCHASE)
            ->where($campaignColumn, $campaignId);

        $this->applyDate($query, 'converted_at', $period);

        if ($linkId !== null) {
            $query->where($linkColumn, $linkId);
        }

        return $this->applyUtm($query, 'utm_source', 'utm_medium', $filters);
    }

    /**
     * @param  array{from: Carbon|null, to: Carbon|null}  $period
     */
    private function applyDate(Builder $query, string $column, array $period): void
    {
        if ($period['from'] !== null) {
            $query->where($column, '>=', $period['from']);
        }

        if ($period['to'] !== null) {
            $query->where($column, '<=', $period['to']);
        }
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function applyUtm(Builder $query, string $sourceColumn, string $mediumColumn, array $filters): Builder
    {
        if (filled($filters['utm_source'] ?? '')) {
            $query->where($sourceColumn, $filters['utm_source']);
        }

        if (filled($filters['utm_medium'] ?? '')) {
            $query->where($mediumColumn, $filters['utm_medium']);
        }

        return $query;
    }

    private function rate(int $numerator, int $denominator): ?float
    {
        if ($denominator <= 0) {
            return null;
        }

        return round(($numerator / $denominator) * 100, 2);
    }

    private function visitVisitorIdentifierColumn(): string
    {
        return Schema::hasColumn('marketing_campaign_visits', 'marketing_campaign_visitor_identity_id')
            ? 'marketing_campaign_visitor_identity_id'
            : 'visitor_token_hash';
    }

    /**
     * @param  array{from: Carbon|null, to: Carbon|null}  $period
     * @param  array<string, string>  $filters
     * @return list<array<string, mixed>>
     */
    private function linkBreakdown(Collection $links, int $campaignId, array $period, array $filters): array
    {
        $visitQuery = DB::table('marketing_campaign_visits')
            ->selectRaw('marketing_campaign_link_id as link_id')
            ->selectRaw('COUNT(*) as visits')
            ->selectRaw("COUNT(DISTINCT {$this->visitVisitorIdentifierColumn()}) as unique_visitors")
            ->where('marketing_campaign_id', $campaignId)
            ->groupBy('marketing_campaign_link_id');
        $this->applyDate($visitQuery, 'visited_at', $period);
        $this->applyUtm($visitQuery, 'utm_source', 'utm_medium', $filters);

        $registrationQuery = DB::table('marketing_campaign_attributions')
            ->leftJoin('marketing_campaign_visits as identified_visit', 'identified_visit.id', '=', 'marketing_campaign_attributions.identified_visit_id')
            ->selectRaw('marketing_campaign_attributions.identified_link_id as link_id')
            ->selectRaw('COUNT(*) as registrations')
            ->where('marketing_campaign_attributions.identified_campaign_id', $campaignId)
            ->whereNotNull('marketing_campaign_attributions.customer_id')
            ->whereNotNull('marketing_campaign_attributions.identified_at')
            ->groupBy('marketing_campaign_attributions.identified_link_id');
        $this->applyDate($registrationQuery, 'marketing_campaign_attributions.identified_at', $period);
        $this->applyUtm($registrationQuery, 'identified_visit.utm_source', 'identified_visit.utm_medium', $filters);

        $conversionQuery = DB::table('marketing_campaign_conversions')
            ->selectRaw('last_link_id as link_id')
            ->selectRaw('COUNT(*) as purchases')
            ->selectRaw('COUNT(DISTINCT customer_id) as buyers')
            ->selectRaw('COALESCE(SUM(amount_cents), 0) as revenue_cents')
            ->where('conversion_type', MarketingCampaignConversion::TYPE_LABORATORY_PURCHASE)
            ->where('last_campaign_id', $campaignId)
            ->groupBy('last_link_id');
        $this->applyDate($conversionQuery, 'converted_at', $period);
        $this->applyUtm($conversionQuery, 'utm_source', 'utm_medium', $filters);

        $visits = $visitQuery->get()->keyBy('link_id');
        $registrations = $registrationQuery->get()->keyBy('link_id');
        $conversions = $conversionQuery->get()->keyBy('link_id');

        return $links->map(function ($link) use ($visits, $registrations, $conversions) {
            $visit = $visits->get($link->id);
            $registration = $registrations->get($link->id);
            $conversion = $conversions->get($link->id);
            $uniqueVisitors = (int) ($visit->unique_visitors ?? 0);
            $registrationCount = (int) ($registration->registrations ?? 0);
            $purchaseCount = (int) ($conversion->purchases ?? 0);

            return [
                'id' => $link->id,
                'name' => $link->name,
                'slug' => $link->slug,
                'visits' => (int) ($visit->visits ?? 0),
                'unique_visitors' => $uniqueVisitors,
                'registrations' => $registrationCount,
                'purchases' => $purchaseCount,
                'buyers' => (int) ($conversion->buyers ?? 0),
                'revenue_cents' => (int) ($conversion->revenue_cents ?? 0),
                'visit_to_purchase_rate' => $this->rate($purchaseCount, $uniqueVisitors),
                'registration_to_purchase_rate' => $this->rate($purchaseCount, $registrationCount),
            ];
        })->values()->all();
    }

    /**
     * @param  array{from: Carbon|null, to: Carbon|null}  $period
     * @param  array<string, string>  $filters
     * @return list<array<string, mixed>>
     */
    private function utmBreakdown(int $campaignId, array $period, ?int $linkId, array $filters): array
    {
        $visitQuery = $this->visitBaseQuery($campaignId, $period, $linkId, $filters)
            ->selectRaw("COALESCE(utm_source, '') as source")
            ->selectRaw("COALESCE(utm_medium, '') as medium")
            ->selectRaw('COUNT(*) as visits')
            ->selectRaw("COUNT(DISTINCT {$this->visitVisitorIdentifierColumn()}) as unique_visitors")
            ->groupBy('utm_source', 'utm_medium');

        $registrationQuery = $this->registrationBaseQuery($campaignId, $period, $linkId, $filters)
            ->selectRaw("COALESCE(identified_visit.utm_source, '') as source")
            ->selectRaw("COALESCE(identified_visit.utm_medium, '') as medium")
            ->selectRaw('COUNT(*) as registrations')
            ->groupBy('identified_visit.utm_source', 'identified_visit.utm_medium');

        $conversionQuery = $this->conversionBaseQuery('last_campaign_id', $campaignId, $period, $linkId, $filters, 'last_link_id')
            ->selectRaw("COALESCE(utm_source, '') as source")
            ->selectRaw("COALESCE(utm_medium, '') as medium")
            ->selectRaw('COUNT(*) as purchases')
            ->selectRaw('COUNT(DISTINCT customer_id) as buyers')
            ->selectRaw('COALESCE(SUM(amount_cents), 0) as revenue_cents')
            ->groupBy('utm_source', 'utm_medium');

        $rows = [];

        foreach ($visitQuery->get() as $row) {
            $rows[$this->utmKey($row->source, $row->medium)] = [
                'source' => $row->source ?: null,
                'medium' => $row->medium ?: null,
                'visits' => (int) $row->visits,
                'unique_visitors' => (int) $row->unique_visitors,
                'registrations' => 0,
                'purchases' => 0,
                'buyers' => 0,
                'revenue_cents' => 0,
            ];
        }

        foreach ($registrationQuery->get() as $row) {
            $key = $this->utmKey($row->source, $row->medium);
            $rows[$key] ??= [
                'source' => $row->source ?: null,
                'medium' => $row->medium ?: null,
                'visits' => 0,
                'unique_visitors' => 0,
                'registrations' => 0,
                'purchases' => 0,
                'buyers' => 0,
                'revenue_cents' => 0,
            ];
            $rows[$key]['registrations'] = (int) $row->registrations;
        }

        foreach ($conversionQuery->get() as $row) {
            $key = $this->utmKey($row->source, $row->medium);
            $rows[$key] ??= [
                'source' => $row->source ?: null,
                'medium' => $row->medium ?: null,
                'visits' => 0,
                'unique_visitors' => 0,
                'registrations' => 0,
                'purchases' => 0,
                'buyers' => 0,
                'revenue_cents' => 0,
            ];
            $rows[$key]['purchases'] = (int) $row->purchases;
            $rows[$key]['buyers'] = (int) $row->buyers;
            $rows[$key]['revenue_cents'] = (int) $row->revenue_cents;
        }

        return collect($rows)
            ->sort(function (array $left, array $right) {
                return [$right['revenue_cents'], $right['purchases'], $right['visits']]
                    <=> [$left['revenue_cents'], $left['purchases'], $left['visits']];
            })
            ->take(12)
            ->values()
            ->all();
    }

    private function utmKey(?string $source, ?string $medium): string
    {
        return ($source ?? '').'|'.($medium ?? '');
    }
}
