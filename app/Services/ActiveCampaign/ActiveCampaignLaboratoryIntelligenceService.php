<?php

namespace App\Services\ActiveCampaign;

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryPurchase;
use App\Support\ActiveCampaign\ActiveCampaignDashboardFilter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Laboratory Intelligence — consola ejecutiva de laboratorios.
 * No modifica Dashboard/Analytics/Carts: agrega lectura propia sobre LaboratoryPurchase
 * y reutiliza overview Dashboard solo como señal cruzada (proxy MI).
 */
class ActiveCampaignLaboratoryIntelligenceService
{
    private const TZ = 'America/Monterrey';

    private ActiveCampaignDashboardService $dashboard;

    private ActiveCampaignAnalyticsService $analytics;

    public function __construct(
        ActiveCampaignDashboardService $dashboard,
        ActiveCampaignAnalyticsService $analytics,
    ) {
        $this->dashboard = $dashboard;
        $this->analytics = $analytics;
    }

    /**
     * Payload inmediato.
     *
     * @return array<string, mixed>
     */
    public function build(Request $request): array
    {
        $filter = ActiveCampaignDashboardFilter::fromRequest($request);

        $resolver = function () use ($filter) {
            $kpis = $this->buildExecutiveKpis($filter);
            $topLabs = $this->buildTopLaboratories($filter);
            $topStudies = $this->buildTopStudies($filter);
            $overview = $this->dashboard->buildOverview($filter);
            $decision = $this->buildDecision($filter, $kpis, $topLabs, $overview);

            return [
                'filters' => $filter->toArray(),
                'summary' => $kpis['cards'],
                'kpis' => $kpis['business'],
                'top_laboratories' => $topLabs,
                'top_studies' => $topStudies,
                'insights' => $decision['insights'],
                'recommendations' => $decision['recommendations'],
                'risks' => $decision['risks'],
                'suggested_actions' => $this->suggestedActions(),
                'gaps' => $this->gaps(),
                'meta' => [
                    ...($overview['meta'] ?? []),
                    'purpose' => 'Analizar el comportamiento completo del negocio de laboratorios Famedic.',
                    'source_of_truth' => 'LaboratoryPurchase (+ items) · señal cruzada Dashboard/Analytics · mapa Timeline laboratory_*',
                    'note' => 'Conversión carrito→compra vive en Monitoreo Carritos; aquí se reporta pipeline resultados/cancelaciones.',
                    'timeline_map' => ['laboratory_purchase', 'laboratory_results', 'invoice'],
                ],
            ];
        };

        if ($filter->bustCache) {
            Cache::forget($this->cacheKey($filter, 'core'));
            Cache::forget($this->cacheKey($filter, 'charts'));
        }

        return Cache::remember($this->cacheKey($filter, 'core'), now()->addMinutes(5), $resolver);
    }

    /**
     * Gráficas diferidas.
     *
     * @return array<string, mixed>
     */
    public function buildCharts(Request $request): array
    {
        $filter = ActiveCampaignDashboardFilter::fromRequest($request);

        $resolver = fn () => [
            'by_day' => $this->seriesByDay($filter),
            'by_week' => $this->seriesByWeek($filter),
            'by_month' => $this->seriesByMonth($filter),
            'by_laboratory' => $this->seriesByLaboratory($filter),
            'by_city' => $this->seriesByCity($filter),
        ];

        if ($filter->bustCache) {
            Cache::forget($this->cacheKey($filter, 'charts'));
        }

        return Cache::remember($this->cacheKey($filter, 'charts'), now()->addMinutes(5), $resolver);
    }

    private function cacheKey(ActiveCampaignDashboardFilter $filter, string $suffix): string
    {
        return 'mi-lab-intel:v1:'.sha1(json_encode($filter->toArray()).'|'.$suffix);
    }

    /**
     * @return array{cards: list<array<string, mixed>>, business: list<array<string, mixed>>}
     */
    private function buildExecutiveKpis(ActiveCampaignDashboardFilter $filter): array
    {
        $current = $this->periodStats($filter->start, $filter->end);
        $previous = $this->periodStats($filter->previousStart, $filter->previousEnd);

        $ticketCurrent = $current['orders'] > 0
            ? (int) round($current['revenue_cents'] / $current['orders'])
            : 0;
        $ticketPrevious = $previous['orders'] > 0
            ? (int) round($previous['revenue_cents'] / $previous['orders'])
            : 0;

        $resultsRate = $current['orders'] > 0
            ? round(100 * $current['with_results'] / $current['orders'], 1)
            : null;
        $resultsRatePrev = $previous['orders'] > 0
            ? round(100 * $previous['with_results'] / $previous['orders'], 1)
            : null;

        $cancelRate = ($current['orders'] + $current['cancelled']) > 0
            ? round(100 * $current['cancelled'] / ($current['orders'] + $current['cancelled']), 1)
            : null;

        $cards = [
            $this->metricCard(
                'ventas',
                'Ventas',
                $this->money($current['revenue_cents']),
                'disponible',
                'lime',
                'SUM(total_cents) compras activas del periodo',
                $this->deltaPct($current['revenue_cents'], $previous['revenue_cents']),
            ),
            $this->metricCard(
                'compras',
                'Compras',
                number_format($current['orders']),
                'disponible',
                'sky',
                'Órdenes activas (sin soft-delete)',
                $this->deltaPct($current['orders'], $previous['orders']),
            ),
            $this->metricCard(
                'ticket',
                'Ticket promedio',
                $this->money($ticketCurrent),
                'disponible',
                'sky',
                'Ventas / compras activas',
                $this->deltaPct($ticketCurrent, $ticketPrevious),
            ),
            $this->metricCard(
                'pacientes',
                'Pacientes',
                number_format($current['patients']),
                'proxy',
                'amber',
                'DISTINCT customer_id — no confirma sync ActiveCampaign',
                $this->deltaPct($current['patients'], $previous['patients']),
            ),
            $this->metricCard(
                'resultados',
                'Resultados',
                number_format($current['with_results']),
                'disponible',
                'lime',
                'Con results o ready_at',
                $this->deltaPct($current['with_results'], $previous['with_results']),
            ),
            $this->metricCard(
                'pendientes',
                'Pendientes',
                number_format($current['pending_results']),
                'proxy',
                'amber',
                'Activas sin resultados listos (pipeline)',
                $this->deltaPct($current['pending_results'], $previous['pending_results'], true),
            ),
            $this->metricCard(
                'conversion',
                'Conversión resultados',
                $resultsRate === null ? '—' : $resultsRate.'%',
                'proxy',
                'sky',
                'Resultados / compras activas (no es carrito→compra)',
                $resultsRate !== null && $resultsRatePrev !== null
                    ? $this->deltaFloat($resultsRate, $resultsRatePrev)
                    : null,
            ),
            $this->metricCard(
                'cancelaciones',
                'Cancelaciones',
                number_format($current['cancelled']).($cancelRate !== null ? " ({$cancelRate}%)" : ''),
                'disponible',
                'red',
                'Soft-deletes en el periodo (criterio admin lab)',
                $this->deltaPct($current['cancelled'], $previous['cancelled'], true),
            ),
        ];

        $business = [
            $this->kpi('ventas', 'Ventas', $current['revenue_cents'] / 100, $previous['revenue_cents'] / 100, 'green', 'disponible', 'MXN', true),
            $this->kpi('compras', 'Compras', $current['orders'], $previous['orders'], 'blue', 'disponible', 'órdenes'),
            $this->kpi('ticket', 'Ticket prom.', $ticketCurrent / 100, $ticketPrevious / 100, 'blue', 'disponible', 'MXN', true),
            $this->kpi('pacientes', 'Pacientes', $current['patients'], $previous['patients'], 'orange', 'proxy', 'customer_id'),
        ];

        return ['cards' => $cards, 'business' => $business];
    }

    /**
     * @return array{revenue_cents: int, orders: int, patients: int, with_results: int, pending_results: int, cancelled: int}
     */
    private function periodStats(Carbon $start, Carbon $end): array
    {
        $expr = $this->activityExpr();
        $startS = $start->toDateTimeString();
        $endS = $end->toDateTimeString();

        $active = LaboratoryPurchase::query()
            ->toBase()
            ->whereNull('deleted_at')
            ->whereRaw("{$expr} BETWEEN ? AND ?", [$startS, $endS])
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(total_cents), 0) as revenue_cents')
            ->selectRaw('COUNT(DISTINCT customer_id) as patients')
            ->selectRaw($this->resultsCaseSql().' as with_results')
            ->first();

        $orders = (int) ($active->orders ?? 0);
        $withResults = (int) ($active->with_results ?? 0);

        $cancelled = (int) LaboratoryPurchase::query()
            ->withTrashed()
            ->toBase()
            ->whereNotNull('deleted_at')
            ->whereBetween('deleted_at', [$startS, $endS])
            ->count();

        return [
            'revenue_cents' => (int) ($active->revenue_cents ?? 0),
            'orders' => $orders,
            'patients' => (int) ($active->patients ?? 0),
            'with_results' => $withResults,
            'pending_results' => max(0, $orders - $withResults),
            'cancelled' => $cancelled,
        ];
    }

    private function resultsCaseSql(): string
    {
        if (Schema::hasColumn('laboratory_purchases', 'ready_at')) {
            return "SUM(CASE WHEN results IS NOT NULL OR ready_at IS NOT NULL THEN 1 ELSE 0 END)";
        }

        return 'SUM(CASE WHEN results IS NOT NULL THEN 1 ELSE 0 END)';
    }

    private function activityExpr(string $table = ''): string
    {
        $prefix = $table !== '' ? $table.'.' : '';

        if (Schema::hasColumn('laboratory_purchases', 'paid_at')) {
            return "COALESCE({$prefix}paid_at, {$prefix}completed_at, {$prefix}created_at)";
        }

        return "{$prefix}created_at";
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildTopLaboratories(ActiveCampaignDashboardFilter $filter): array
    {
        $expr = $this->activityExpr();
        $cStart = $filter->start->toDateTimeString();
        $cEnd = $filter->end->toDateTimeString();
        $pStart = $filter->previousStart->toDateTimeString();
        $pEnd = $filter->previousEnd->toDateTimeString();

        $current = LaboratoryPurchase::query()
            ->toBase()
            ->whereNull('deleted_at')
            ->whereRaw("{$expr} BETWEEN ? AND ?", [$cStart, $cEnd])
            ->selectRaw('brand')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(total_cents), 0) as revenue_cents')
            ->groupBy('brand')
            ->get()
            ->keyBy('brand');

        $previous = LaboratoryPurchase::query()
            ->toBase()
            ->whereNull('deleted_at')
            ->whereRaw("{$expr} BETWEEN ? AND ?", [$pStart, $pEnd])
            ->selectRaw('brand')
            ->selectRaw('COALESCE(SUM(total_cents), 0) as revenue_cents')
            ->selectRaw('COUNT(*) as orders')
            ->groupBy('brand')
            ->get()
            ->keyBy('brand');

        $totalRevenue = (int) $current->sum('revenue_cents');

        return collect(LaboratoryBrand::cases())
            ->map(function (LaboratoryBrand $brand) use ($current, $previous, $totalRevenue) {
                $cur = $current->get($brand->value);
                $prev = $previous->get($brand->value);
                $revenue = (int) ($cur->revenue_cents ?? 0);
                $orders = (int) ($cur->orders ?? 0);
                $prevRevenue = (int) ($prev->revenue_cents ?? 0);
                $delta = $this->deltaPct($revenue, $prevRevenue);

                return [
                    'id' => $brand->value,
                    'label' => $brand->label(),
                    'orders' => $orders,
                    'orders_label' => number_format($orders),
                    'revenue_cents' => $revenue,
                    'revenue_label' => $this->money($revenue),
                    'share_percent' => $totalRevenue > 0
                        ? round(100 * $revenue / $totalRevenue, 1)
                        : 0.0,
                    'growth' => $delta,
                    'growth_label' => $delta['label'] ?? '—',
                    'truth' => 'disponible',
                ];
            })
            ->filter(fn (array $row) => $row['orders'] > 0 || $row['revenue_cents'] > 0)
            ->sortByDesc('revenue_cents')
            ->values()
            ->all();
    }

    /**
     * @return array{by_quantity: list<array<string, mixed>>, by_revenue: list<array<string, mixed>>, by_growth: list<array<string, mixed>>}
     */
    private function buildTopStudies(ActiveCampaignDashboardFilter $filter): array
    {
        $expr = $this->activityExpr('lp');
        $cStart = $filter->start->toDateTimeString();
        $cEnd = $filter->end->toDateTimeString();
        $pStart = $filter->previousStart->toDateTimeString();
        $pEnd = $filter->previousEnd->toDateTimeString();

        $current = DB::table('laboratory_purchase_items as lpi')
            ->join('laboratory_purchases as lp', 'lp.id', '=', 'lpi.laboratory_purchase_id')
            ->whereNull('lpi.deleted_at')
            ->whereNull('lp.deleted_at')
            ->whereRaw("{$expr} BETWEEN ? AND ?", [$cStart, $cEnd])
            ->selectRaw('lpi.gda_id as study_id, lpi.name as study_name, COUNT(*) as quantity, COALESCE(SUM(lpi.price_cents), 0) as revenue_cents')
            ->groupBy('lpi.gda_id', 'lpi.name')
            ->orderByDesc('quantity')
            ->limit(15)
            ->get();

        $previousByKey = DB::table('laboratory_purchase_items as lpi')
            ->join('laboratory_purchases as lp', 'lp.id', '=', 'lpi.laboratory_purchase_id')
            ->whereNull('lpi.deleted_at')
            ->whereNull('lp.deleted_at')
            ->whereRaw("{$expr} BETWEEN ? AND ?", [$pStart, $pEnd])
            ->selectRaw('lpi.gda_id as study_id, lpi.name as study_name, COUNT(*) as quantity, COALESCE(SUM(lpi.price_cents), 0) as revenue_cents')
            ->groupBy('lpi.gda_id', 'lpi.name')
            ->get()
            ->keyBy(fn ($row) => ($row->study_id ?: '').'|'.$row->study_name);

        $mapRow = function ($row) use ($previousByKey) {
            $key = ($row->study_id ?: '').'|'.$row->study_name;
            $prev = $previousByKey->get($key);
            $qty = (int) $row->quantity;
            $prevQty = (int) ($prev->quantity ?? 0);
            $revenue = (int) $row->revenue_cents;
            $delta = $this->deltaPct($qty, $prevQty);

            return [
                'id' => (string) ($row->study_id ?: $row->study_name),
                'name' => (string) $row->study_name,
                'quantity' => $qty,
                'quantity_label' => number_format($qty),
                'revenue_cents' => $revenue,
                'revenue_label' => $this->money($revenue),
                'growth' => $delta,
                'growth_label' => $delta['label'] ?? '—',
                'truth' => 'disponible',
            ];
        };

        $byQuantity = $current->map($mapRow)->values()->all();
        $byRevenue = collect($byQuantity)->sortByDesc('revenue_cents')->values()->take(10)->all();
        $byGrowth = collect($byQuantity)
            ->filter(fn (array $r) => ($r['growth']['percent'] ?? null) !== null)
            ->sortByDesc(fn (array $r) => $r['growth']['percent'])
            ->values()
            ->take(10)
            ->all();

        return [
            'by_quantity' => array_slice($byQuantity, 0, 10),
            'by_revenue' => $byRevenue,
            'by_growth' => $byGrowth,
        ];
    }

    /**
     * @return list<array{label: string, orders: int, revenue: float}>
     */
    private function seriesByDay(ActiveCampaignDashboardFilter $filter): array
    {
        $expr = $this->activityExpr();

        return LaboratoryPurchase::query()
            ->toBase()
            ->whereNull('deleted_at')
            ->whereRaw("{$expr} BETWEEN ? AND ?", [
                $filter->start->toDateTimeString(),
                $filter->end->toDateTimeString(),
            ])
            ->selectRaw("DATE({$expr}) as day_key")
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(total_cents), 0) as revenue_cents')
            ->groupBy('day_key')
            ->orderBy('day_key')
            ->get()
            ->map(function ($row) {
                $day = Carbon::parse((string) $row->day_key, self::TZ);

                return [
                    'label' => $day->format('d/m'),
                    'orders' => (int) $row->orders,
                    'revenue' => round(((int) $row->revenue_cents) / 100, 2),
                ];
            })
            ->all();
    }

    /**
     * @return list<array{label: string, orders: int, revenue: float}>
     */
    private function seriesByWeek(ActiveCampaignDashboardFilter $filter): array
    {
        return $this->bucketSeries($filter, 'WEEK');
    }

    /**
     * @return list<array{label: string, orders: int, revenue: float}>
     */
    private function seriesByMonth(ActiveCampaignDashboardFilter $filter): array
    {
        return $this->bucketSeries($filter, 'MONTH');
    }

    /**
     * @return list<array{label: string, orders: int, revenue: float}>
     */
    private function bucketSeries(ActiveCampaignDashboardFilter $filter, string $grain): array
    {
        $expr = $this->activityExpr();
        $format = $grain === 'MONTH' ? '%Y-%m' : '%x-W%v';

        $rows = LaboratoryPurchase::query()
            ->toBase()
            ->whereNull('deleted_at')
            ->whereRaw("{$expr} BETWEEN ? AND ?", [
                $filter->start->toDateTimeString(),
                $filter->end->toDateTimeString(),
            ])
            ->selectRaw("DATE_FORMAT({$expr}, '{$format}') as bucket")
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(total_cents), 0) as revenue_cents')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return $rows->map(fn ($row) => [
            'label' => (string) $row->bucket,
            'orders' => (int) $row->orders,
            'revenue' => round(((int) $row->revenue_cents) / 100, 2),
        ])->all();
    }

    /**
     * @return list<array{label: string, orders: int, revenue: float}>
     */
    private function seriesByLaboratory(ActiveCampaignDashboardFilter $filter): array
    {
        $expr = $this->activityExpr();

        return LaboratoryPurchase::query()
            ->toBase()
            ->whereNull('deleted_at')
            ->whereRaw("{$expr} BETWEEN ? AND ?", [
                $filter->start->toDateTimeString(),
                $filter->end->toDateTimeString(),
            ])
            ->selectRaw('brand')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(total_cents), 0) as revenue_cents')
            ->groupBy('brand')
            ->orderByDesc('revenue_cents')
            ->get()
            ->map(function ($row) {
                $brand = LaboratoryBrand::tryFrom((string) $row->brand);

                return [
                    'label' => $brand?->label() ?? (string) $row->brand,
                    'orders' => (int) $row->orders,
                    'revenue' => round(((int) $row->revenue_cents) / 100, 2),
                ];
            })
            ->all();
    }

    /**
     * @return list<array{label: string, orders: int, revenue: float}>
     */
    private function seriesByCity(ActiveCampaignDashboardFilter $filter): array
    {
        $expr = $this->activityExpr();

        return LaboratoryPurchase::query()
            ->toBase()
            ->whereNull('deleted_at')
            ->whereRaw("{$expr} BETWEEN ? AND ?", [
                $filter->start->toDateTimeString(),
                $filter->end->toDateTimeString(),
            ])
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->selectRaw('city')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(total_cents), 0) as revenue_cents')
            ->groupBy('city')
            ->orderByDesc('orders')
            ->limit(12)
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->city,
                'orders' => (int) $row->orders,
                'revenue' => round(((int) $row->revenue_cents) / 100, 2),
            ])
            ->all();
    }

    /**
     * @param  array{cards: list<array<string, mixed>>}  $kpis
     * @param  list<array<string, mixed>>  $topLabs
     * @param  array<string, mixed>  $overview
     * @return array{insights: list<array<string, mixed>>, recommendations: list<array<string, mixed>>, risks: list<array<string, mixed>>}
     */
    private function buildDecision(
        ActiveCampaignDashboardFilter $filter,
        array $kpis,
        array $topLabs,
        array $overview,
    ): array {
        $analytics = $this->analytics->build($filter);
        $businessDomain = collect($analytics['domains'] ?? [])->firstWhere('id', 'business') ?? [];

        $insights = [
            $this->item(
                'Laboratory Intelligence agrega LaboratoryPurchase reales (ventas, órdenes, resultados, cancelaciones).',
                'disponible',
            ),
        ];

        $pendientes = collect($kpis['cards'])->firstWhere('id', 'pendientes');
        $cancel = collect($kpis['cards'])->firstWhere('id', 'cancelaciones');

        if ($topLabs !== []) {
            $leader = $topLabs[0];
            $insights[] = $this->item(
                "Líder del periodo: {$leader['label']} con {$leader['revenue_label']} ({$leader['share_percent']}% participación).",
                'disponible',
            );
        }

        foreach ($businessDomain['insights'] ?? [] as $item) {
            $insights[] = $item;
        }

        $recommendations = [
            $this->item(
                'Contrastar pacientes top con Customer Journey / Timeline (laboratory_purchase, laboratory_results).',
                'disponible',
            ),
            $this->item(
                'Para conversión carrito→compra usar Monitoreo · Carritos (fuera de esta consola MI).',
                'proxy',
            ),
        ];
        foreach ($businessDomain['recommendations'] ?? [] as $item) {
            $recommendations[] = $item;
        }

        $risks = [];
        $pendingValue = (int) preg_replace('/[^\d]/', '', (string) ($pendientes['value'] ?? '0'));
        if ($pendingValue > 0) {
            $risks[] = $this->item(
                "Hay {$pendingValue} compras activas sin resultados listos en el periodo.",
                'proxy',
            );
        }
        if ($cancel && str_contains((string) $cancel['value'], '(')) {
            $risks[] = $this->item(
                'Revisar tasa de cancelaciones (soft-delete) vs periodo anterior.',
                'disponible',
            );
        }
        foreach ($businessDomain['risks'] ?? [] as $item) {
            $risks[] = $item;
        }

        $dashLab = collect($overview['business'] ?? [])->firstWhere('id', 'lab');
        if ($dashLab) {
            $insights[] = $this->item(
                'Señal cruzada Dashboard KPI Lab (proxy triggers): '.$dashLab['value_formatted'].'.',
                'proxy',
            );
        }

        return [
            'insights' => array_slice($insights, 0, 8),
            'recommendations' => array_slice($recommendations, 0, 6),
            'risks' => array_slice($risks, 0, 6),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function suggestedActions(): array
    {
        return [
            [
                'id' => 'dashboard',
                'label' => 'Abrir Dashboard',
                'href' => route('admin.activecampaign.dashboard'),
                'enabled' => true,
            ],
            [
                'id' => 'analytics',
                'label' => 'Abrir Analytics',
                'href' => route('admin.activecampaign.analytics'),
                'enabled' => true,
            ],
            [
                'id' => 'funnels',
                'label' => 'Funnel Laboratorios',
                'href' => route('admin.activecampaign.funnels', ['funnel' => 'laboratories']),
                'enabled' => true,
            ],
            [
                'id' => 'journey',
                'label' => 'Customer Journey',
                'href' => route('admin.activecampaign.customer-journey'),
                'enabled' => true,
            ],
            [
                'id' => 'events',
                'label' => 'Event Center',
                'href' => route('admin.activecampaign.events'),
                'enabled' => true,
            ],
            [
                'id' => 'crm',
                'label' => 'CRM Contactos',
                'href' => route('admin.activecampaign.contacts'),
                'enabled' => true,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gaps(): array
    {
        return [
            [
                'label' => 'Conversión carrito → compra',
                'reason' => 'Requiere carts abandonados/completados (Monitoreo Carritos), no solo LaboratoryPurchase.',
                'truth' => 'instrumentacion',
            ],
            [
                'label' => 'Toma de muestra agregada',
                'reason' => 'sample_collected / notificaciones GDA no están KPI de este módulo aún.',
                'truth' => 'instrumentacion',
            ],
            [
                'label' => 'Facturación lab agregada en MI',
                'reason' => 'Existe Laboratory Billing; no está unificado aquí.',
                'truth' => 'proximamente',
            ],
            [
                'label' => 'Atribución marketing / campañas',
                'reason' => 'GA/Meta no conectados al Intelligence Platform.',
                'truth' => 'instrumentacion',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metricCard(
        string $id,
        string $label,
        string $value,
        string $truth,
        string $tone,
        string $hint,
        ?array $delta,
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'value' => $value,
            'truth' => $truth,
            'tone' => $tone,
            'hint' => $hint,
            'delta' => $delta['label'] ?? null,
            'delta_is_positive' => $delta['is_positive'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function kpi(
        string $id,
        string $label,
        float|int $current,
        float|int $previous,
        string $tone,
        string $truth,
        string $hint,
        bool $money = false,
    ): array {
        $delta = $this->deltaFloat((float) $current, (float) $previous);

        return [
            'id' => $id,
            'label' => $label,
            'value_formatted' => $money
                ? '$'.number_format((float) $current, 2)
                : number_format((int) $current),
            'previous_formatted' => $money
                ? '$'.number_format((float) $previous, 2)
                : number_format((int) $previous),
            'hint' => $hint,
            'tone' => $tone,
            'truth' => $truth,
            'source' => 'laboratory_purchases',
            'sparkline' => [],
            'delta_percent' => $delta['percent'],
            'delta_direction' => $delta['direction'],
            'delta_is_positive' => $delta['is_positive'],
        ];
    }

    private function money(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }

    /**
     * @return array{percent: float|null, direction: string, is_positive: bool|null, label: string}|null
     */
    private function deltaPct(int $current, int $previous, bool $higherIsWorse = false): ?array
    {
        return $this->deltaFloat((float) $current, (float) $previous, $higherIsWorse);
    }

    /**
     * @return array{percent: float|null, direction: string, is_positive: bool|null, label: string}
     */
    private function deltaFloat(float $current, float $previous, bool $higherIsWorse = false): array
    {
        if ($previous == 0.0) {
            if ($current == 0.0) {
                return [
                    'percent' => 0.0,
                    'direction' => 'flat',
                    'is_positive' => null,
                    'label' => '0%',
                ];
            }

            return [
                'percent' => 100.0,
                'direction' => 'up',
                'is_positive' => ! $higherIsWorse,
                'label' => '+100%',
            ];
        }

        $raw = (($current - $previous) / abs($previous)) * 100;
        $pct = round(abs($raw), 1);
        $direction = $raw > 0.05 ? 'up' : ($raw < -0.05 ? 'down' : 'flat');
        $isPositive = $direction === 'flat'
            ? null
            : (($direction === 'up') xor $higherIsWorse);

        $label = $direction === 'flat'
            ? '0%'
            : (($direction === 'down' ? '−' : '+').$pct.'%');

        return [
            'percent' => $pct,
            'direction' => $direction,
            'is_positive' => $isPositive,
            'label' => $label,
        ];
    }

    /**
     * @return array{text: string, truth: string}
     */
    private function item(string $text, string $truth): array
    {
        return ['text' => $text, 'truth' => $truth];
    }
}
