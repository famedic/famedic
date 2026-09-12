<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignConversion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MarketingCampaignAttributedUsersPresenter
{
    /**
     * @param  array{from?: string, to?: string, link_id?: string, utm_source?: string, utm_medium?: string, status?: string, per_page?: int}  $filters
     * @return array<string, mixed>
     */
    public function paginated(MarketingCampaign $campaign, array $filters, bool $includePii): array
    {
        $perPage = max(10, min(100, (int) ($filters['per_page'] ?? 25)));
        $paginator = $this->baseQuery($campaign, $filters)
            ->orderByDesc('attributions.identified_at')
            ->orderByDesc('attributions.id')
            ->paginate($perPage, ['*'], 'attributed_page')
            ->withQueryString();

        return [
            'data' => $paginator->getCollection()
                ->map(fn (object $row) => $this->rowPayload($row, $includePii))
                ->values()
                ->all(),
            'meta' => $this->paginationMeta($paginator),
            'links' => $this->paginationLinks($paginator),
            'filters' => [
                'from' => (string) ($filters['from'] ?? ''),
                'to' => (string) ($filters['to'] ?? ''),
                'link_id' => (string) ($filters['link_id'] ?? ''),
                'utm_source' => (string) ($filters['utm_source'] ?? ''),
                'utm_medium' => (string) ($filters['utm_medium'] ?? ''),
                'status' => (string) ($filters['status'] ?? ''),
                'per_page' => $perPage,
            ],
            'can_view_pii' => $includePii,
        ];
    }

    /**
     * @param  array{from?: string, to?: string, link_id?: string, utm_source?: string, utm_medium?: string, status?: string}  $filters
     * @return list<array<string, mixed>>
     */
    public function exportRows(MarketingCampaign $campaign, array $filters, bool $includePii): array
    {
        return $this->baseQuery($campaign, $filters)
            ->orderByDesc('attributions.identified_at')
            ->orderByDesc('attributions.id')
            ->get()
            ->map(fn (object $row) => $this->csvRow($row, $includePii))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string|int>  $filters
     */
    private function baseQuery(MarketingCampaign $campaign, array $filters): Builder
    {
        $period = $this->periodBounds($filters);
        $linkId = filled($filters['link_id'] ?? '') ? (int) $filters['link_id'] : null;

        $query = DB::table('marketing_campaign_attributions as attributions')
            ->leftJoin('users', 'users.id', '=', 'attributions.user_id')
            ->leftJoin('customers', 'customers.id', '=', 'attributions.customer_id')
            ->leftJoin('marketing_campaign_visits as identified_visit', 'identified_visit.id', '=', 'attributions.identified_visit_id')
            ->leftJoin('marketing_campaigns as first_campaigns', 'first_campaigns.id', '=', 'attributions.first_campaign_id')
            ->leftJoin('marketing_campaign_links as first_links', 'first_links.id', '=', 'attributions.first_link_id')
            ->leftJoin('marketing_campaigns as last_campaigns', 'last_campaigns.id', '=', 'attributions.last_campaign_id')
            ->leftJoin('marketing_campaign_links as last_links', 'last_links.id', '=', 'attributions.last_link_id')
            ->leftJoin('marketing_campaigns as identified_campaigns', 'identified_campaigns.id', '=', 'attributions.identified_campaign_id')
            ->leftJoin('marketing_campaign_links as identified_links', 'identified_links.id', '=', 'attributions.identified_link_id')
            ->leftJoinSub($this->conversionSummary($campaign->id), 'conversion_summary', function ($join) {
                $join->on('conversion_summary.attribution_id', '=', 'attributions.id');
            })
            ->whereNotNull('attributions.identified_at')
            ->where(function (Builder $query) {
                $query->whereNotNull('attributions.user_id')
                    ->orWhereNotNull('attributions.customer_id');
            })
            ->where(function (Builder $query) use ($campaign) {
                $query->where('attributions.identified_campaign_id', $campaign->id)
                    ->orWhere('attributions.first_campaign_id', $campaign->id)
                    ->orWhere('attributions.last_campaign_id', $campaign->id);
            })
            ->select([
                'attributions.id as attribution_id',
                'attributions.first_touched_at',
                'attributions.last_touched_at',
                'attributions.identified_at',
                'users.name as user_name',
                'users.paternal_lastname as user_paternal_lastname',
                'users.maternal_lastname as user_maternal_lastname',
                'users.email as user_email',
                'identified_visit.utm_source as identified_utm_source',
                'identified_visit.utm_medium as identified_utm_medium',
                'identified_visit.utm_campaign as identified_utm_campaign',
                'identified_visit.utm_term as identified_utm_term',
                'identified_visit.utm_content as identified_utm_content',
                'first_campaigns.name as first_campaign_name',
                'first_links.name as first_link_name',
                'first_links.slug as first_link_slug',
                'last_campaigns.name as last_campaign_name',
                'last_links.name as last_link_name',
                'last_links.slug as last_link_slug',
                'identified_campaigns.name as identified_campaign_name',
                'identified_links.name as identified_link_name',
                'identified_links.slug as identified_link_slug',
            ])
            ->selectRaw('COALESCE(conversion_summary.conversions_count, 0) as conversions_count')
            ->selectRaw('COALESCE(conversion_summary.revenue_cents, 0) as revenue_cents')
            ->selectRaw('COALESCE(conversion_summary.first_touch_conversions_count, 0) as first_touch_conversions_count')
            ->selectRaw('COALESCE(conversion_summary.first_touch_revenue_cents, 0) as first_touch_revenue_cents')
            ->selectRaw('COALESCE(conversion_summary.last_touch_conversions_count, 0) as last_touch_conversions_count')
            ->selectRaw('COALESCE(conversion_summary.last_touch_revenue_cents, 0) as last_touch_revenue_cents')
            ->selectRaw('conversion_summary.last_converted_at as last_converted_at');

        $this->applyDate($query, 'attributions.identified_at', $period);

        if ($linkId !== null) {
            $query->where(function (Builder $query) use ($linkId) {
                $query->where('attributions.identified_link_id', $linkId)
                    ->orWhere('attributions.first_link_id', $linkId)
                    ->orWhere('attributions.last_link_id', $linkId);
            });
        }

        if (filled($filters['utm_source'] ?? '')) {
            $query->where('identified_visit.utm_source', $filters['utm_source']);
        }

        if (filled($filters['utm_medium'] ?? '')) {
            $query->where('identified_visit.utm_medium', $filters['utm_medium']);
        }

        if (($filters['status'] ?? '') === 'buyer') {
            $query->whereRaw('COALESCE(conversion_summary.conversions_count, 0) > 0');
        }

        if (($filters['status'] ?? '') === 'registered_without_purchase') {
            $query->whereRaw('COALESCE(conversion_summary.conversions_count, 0) = 0');
        }

        return $query;
    }

    private function conversionSummary(int $campaignId): Builder
    {
        return DB::table('marketing_campaign_conversions')
            ->selectRaw('marketing_campaign_attribution_id as attribution_id')
            ->selectRaw('COUNT(*) as conversions_count')
            ->selectRaw('COALESCE(SUM(amount_cents), 0) as revenue_cents')
            ->selectRaw('SUM(CASE WHEN first_campaign_id = ? THEN 1 ELSE 0 END) as first_touch_conversions_count', [$campaignId])
            ->selectRaw('COALESCE(SUM(CASE WHEN first_campaign_id = ? THEN amount_cents ELSE 0 END), 0) as first_touch_revenue_cents', [$campaignId])
            ->selectRaw('SUM(CASE WHEN last_campaign_id = ? THEN 1 ELSE 0 END) as last_touch_conversions_count', [$campaignId])
            ->selectRaw('COALESCE(SUM(CASE WHEN last_campaign_id = ? THEN amount_cents ELSE 0 END), 0) as last_touch_revenue_cents', [$campaignId])
            ->selectRaw('MAX(converted_at) as last_converted_at')
            ->where('conversion_type', MarketingCampaignConversion::TYPE_LABORATORY_PURCHASE)
            ->groupBy('marketing_campaign_attribution_id');
    }

    /**
     * @param  array<string, string|int>  $filters
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
     * @return array<string, mixed>
     */
    private function rowPayload(object $row, bool $includePii): array
    {
        return [
            'attribution' => [
                'reference' => $this->reference($row->attribution_id),
                'identified_at' => $row->identified_at,
                'first_touched_at' => $row->first_touched_at,
                'last_touched_at' => $row->last_touched_at,
            ],
            'user' => $this->userPayload($row, $includePii),
            'registration' => [
                'campaign' => $row->identified_campaign_name,
                'link' => $this->linkPayload($row->identified_link_name, $row->identified_link_slug),
                'utm_source' => $row->identified_utm_source,
                'utm_medium' => $row->identified_utm_medium,
                'utm_campaign' => $row->identified_utm_campaign,
                'utm_term' => $row->identified_utm_term,
                'utm_content' => $row->identified_utm_content,
            ],
            'first_touch' => [
                'campaign' => $row->first_campaign_name,
                'link' => $this->linkPayload($row->first_link_name, $row->first_link_slug),
                'conversions' => (int) $row->first_touch_conversions_count,
                'revenue_cents' => (int) $row->first_touch_revenue_cents,
            ],
            'last_touch' => [
                'campaign' => $row->last_campaign_name,
                'link' => $this->linkPayload($row->last_link_name, $row->last_link_slug),
                'conversions' => (int) $row->last_touch_conversions_count,
                'revenue_cents' => (int) $row->last_touch_revenue_cents,
            ],
            'conversions' => [
                'count' => (int) $row->conversions_count,
                'revenue_cents' => (int) $row->revenue_cents,
                'last_converted_at' => $row->last_converted_at,
                'is_buyer' => (int) $row->conversions_count > 0,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function csvRow(object $row, bool $includePii): array
    {
        $payload = $this->rowPayload($row, $includePii);

        $base = [
            'referencia_atribucion' => $payload['attribution']['reference'],
            'identificado_en' => $payload['attribution']['identified_at'],
        ];

        if ($includePii) {
            $base['nombre'] = $payload['user']['name'];
            $base['correo'] = $payload['user']['email'];
        }

        return [
            ...$base,
            'estado' => $payload['conversions']['is_buyer'] ? 'comprador' : 'registrado',
            'campana_registro' => $payload['registration']['campaign'],
            'enlace_registro' => $payload['registration']['link']['name'],
            'slug_registro' => $payload['registration']['link']['slug'],
            'utm_source' => $payload['registration']['utm_source'],
            'utm_medium' => $payload['registration']['utm_medium'],
            'utm_campaign' => $payload['registration']['utm_campaign'],
            'utm_term' => $payload['registration']['utm_term'],
            'utm_content' => $payload['registration']['utm_content'],
            'campana_first_touch' => $payload['first_touch']['campaign'],
            'enlace_first_touch' => $payload['first_touch']['link']['name'],
            'campana_last_touch' => $payload['last_touch']['campaign'],
            'enlace_last_touch' => $payload['last_touch']['link']['name'],
            'conversiones_total' => $payload['conversions']['count'],
            'revenue_total_centavos' => $payload['conversions']['revenue_cents'],
            'conversiones_first_touch' => $payload['first_touch']['conversions'],
            'revenue_first_touch_centavos' => $payload['first_touch']['revenue_cents'],
            'conversiones_last_touch' => $payload['last_touch']['conversions'],
            'revenue_last_touch_centavos' => $payload['last_touch']['revenue_cents'],
            'ultima_conversion_en' => $payload['conversions']['last_converted_at'],
        ];
    }

    /**
     * @return array{name: string|null, email: string|null, label: string}
     */
    private function userPayload(object $row, bool $includePii): array
    {
        if (! $includePii) {
            return [
                'name' => null,
                'email' => null,
                'label' => 'Usuario identificado',
            ];
        }

        $name = collect([
            $row->user_name,
            $row->user_paternal_lastname,
            $row->user_maternal_lastname,
        ])->filter(fn ($value) => filled($value))->implode(' ');

        return [
            'name' => $name !== '' ? $name : null,
            'email' => $row->user_email,
            'label' => $name !== '' ? $name : ($row->user_email ?: 'Usuario identificado'),
        ];
    }

    /**
     * @return array{name: string|null, slug: string|null}
     */
    private function linkPayload(?string $name, ?string $slug): array
    {
        return [
            'name' => $name,
            'slug' => $slug,
        ];
    }

    private function reference(int|string $id): string
    {
        return 'ATTR-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, mixed>
     */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'from' => $paginator->firstItem(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function paginationLinks(LengthAwarePaginator $paginator): array
    {
        return [
            'first' => $paginator->url(1),
            'last' => $paginator->url($paginator->lastPage()),
            'prev' => $paginator->previousPageUrl(),
            'next' => $paginator->nextPageUrl(),
        ];
    }
}
