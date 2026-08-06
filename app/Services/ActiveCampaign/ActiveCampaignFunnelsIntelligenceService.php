<?php

namespace App\Services\ActiveCampaign;

use App\Support\ActiveCampaign\ActiveCampaignDashboardFilter;
use Illuminate\Http\Request;

/**
 * Funnels Intelligence — embudos de conversión sobre proxies existentes.
 * No crea fuente de verdad: reutiliza Dashboard (vía Analytics) y mapea etapas
 * a tipos conocidos de Timeline / Customer Journey / Event Center.
 */
class ActiveCampaignFunnelsIntelligenceService
{
    private const UNAVAILABLE = '—';

    private const FUNNELS = [
        'general' => 'Funnel General',
        'laboratories' => 'Funnel Laboratorios',
        'pharmacy' => 'Funnel Farmacia',
        'memberships' => 'Funnel Membresías',
    ];

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
     * Payload inmediato: resumen, selector, etapas, métricas, insights.
     *
     * @return array<string, mixed>
     */
    public function build(Request $request): array
    {
        $filter = ActiveCampaignDashboardFilter::fromRequest($request);
        $funnelId = $this->resolveFunnelId($request);
        $overview = $this->dashboard->buildOverview($filter);
        $health = collect($overview['health'])->keyBy('id');
        $business = collect($overview['business'])->keyBy('id');

        $stages = $this->buildStages($funnelId, $health, $business);
        $metrics = $this->buildMetricsTable($stages);
        $decision = $this->buildDecision($funnelId, $filter, $health, $business, $stages);

        return [
            'filters' => [
                ...$filter->toArray(),
                'funnel' => $funnelId,
            ],
            'funnelOptions' => collect(self::FUNNELS)
                ->map(fn (string $label, string $id) => ['value' => $id, 'label' => $label])
                ->values()
                ->all(),
            'summary' => $this->buildSummary($health, $business),
            'funnel' => [
                'id' => $funnelId,
                'label' => self::FUNNELS[$funnelId],
                'description' => $this->funnelDescription($funnelId),
                'timeline_map' => $this->timelineMap($funnelId),
                'stages' => $stages,
            ],
            'metrics' => $metrics,
            'insights' => $decision['insights'],
            'recommendations' => $decision['recommendations'],
            'risks' => $decision['risks'],
            'suggested_actions' => $this->suggestedActions($funnelId),
            'gaps' => $this->gaps($funnelId),
            'meta' => [
                ...($overview['meta'] ?? []),
                'purpose' => 'Visualizar recorridos reales de pacientes por servicio, con honestidad de dato.',
                'source_of_truth' => 'DashboardService (+ Analytics) · tipos Timeline/Journey/Event Center (mapa)',
                'note' => 'Conversión cohort y tiempos entre etapas requieren instrumentación; volúmenes reutilizan proxies del Dashboard.',
            ],
        ];
    }

    /**
     * Gráficas diferidas: series Dashboard + barras del embudo seleccionado.
     *
     * @return array<string, mixed>
     */
    public function buildCharts(Request $request): array
    {
        $filter = ActiveCampaignDashboardFilter::fromRequest($request);
        $funnelId = $this->resolveFunnelId($request);
        $overview = $this->dashboard->buildOverview($filter);
        $health = collect($overview['health'])->keyBy('id');
        $business = collect($overview['business'])->keyBy('id');
        $stages = $this->buildStages($funnelId, $health, $business);

        $dashboardCharts = $this->dashboard->buildCharts($filter);

        return [
            'funnel_bars' => $this->funnelBarSeries($stages),
            'funnel_compare' => $this->funnelCompareSeries($stages),
            'events_by_type' => $dashboardCharts['events_by_type'] ?? [],
            'dispatches_by_day' => $dashboardCharts['dispatches_by_day'] ?? [],
        ];
    }

    private function resolveFunnelId(Request $request): string
    {
        $id = trim((string) $request->input('funnel', 'general'));

        return array_key_exists($id, self::FUNNELS) ? $id : 'general';
    }

    /**
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $health
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $business
     * @return list<array<string, mixed>>
     */
    private function buildSummary($health, $business): array
    {
        $patients = $health->get('patients');
        $lab = $business->get('lab');
        $pharmacy = $business->get('pharmacy');
        $membership = $business->get('membership');
        $abandoned = $business->get('abandoned');

        return [
            [
                'id' => 'patients',
                'label' => 'Registros (proxy)',
                'value' => (string) ($patients['value'] ?? self::UNAVAILABLE),
                'hint' => $patients['hint'] ?? 'Pacientes creados en el periodo',
                'tone' => 'sky',
                'truth' => $patients['truth'] ?? 'proxy',
            ],
            [
                'id' => 'lab',
                'label' => 'Lab · compras',
                'value' => (string) ($lab['value_formatted'] ?? self::UNAVAILABLE),
                'hint' => $lab['hint'] ?? null,
                'tone' => 'blue',
                'truth' => $lab['truth'] ?? 'proxy',
                'delta' => $this->deltaSnippet($lab),
            ],
            [
                'id' => 'pharmacy',
                'label' => 'Farmacia · compras',
                'value' => (string) ($pharmacy['value_formatted'] ?? self::UNAVAILABLE),
                'hint' => $pharmacy['hint'] ?? null,
                'tone' => 'purple',
                'truth' => $pharmacy['truth'] ?? 'proxy',
                'delta' => $this->deltaSnippet($pharmacy),
            ],
            [
                'id' => 'membership',
                'label' => 'Membresías · altas',
                'value' => (string) ($membership['value_formatted'] ?? self::UNAVAILABLE),
                'hint' => $membership['hint'] ?? null,
                'tone' => 'green',
                'truth' => $membership['truth'] ?? 'proxy',
                'delta' => $this->deltaSnippet($membership),
            ],
            [
                'id' => 'abandoned',
                'label' => 'Abandonos carrito',
                'value' => (string) ($abandoned['value_formatted'] ?? self::UNAVAILABLE),
                'hint' => $abandoned['hint'] ?? 'Tag AC de abandono',
                'tone' => 'amber',
                'truth' => $abandoned['truth'] ?? 'disponible',
                'delta' => $this->deltaSnippet($abandoned),
            ],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $health
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $business
     * @return list<array<string, mixed>>
     */
    private function buildStages(string $funnelId, $health, $business): array
    {
        $stages = match ($funnelId) {
            'laboratories' => $this->labsStages($business),
            'pharmacy' => $this->pharmacyStages($business),
            'memberships' => $this->membershipsStages($health, $business),
            default => $this->generalStages($health, $business),
        };

        return $this->withFunnelWidths($stages);
    }

    /**
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $health
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $business
     * @return list<array<string, mixed>>
     */
    private function generalStages($health, $business): array
    {
        $patients = $health->get('patients');
        $lab = $business->get('lab');
        $pharmacy = $business->get('pharmacy');
        $membership = $business->get('membership');

        $labN = $this->kpiInt($lab);
        $phN = $this->kpiInt($pharmacy);
        $purchaseN = ($labN !== null && $phN !== null) ? $labN + $phN : ($labN ?? $phN);

        return [
            $this->stageFromHealth('registration', 'Registro', $patients, 'Timeline · registration'),
            $this->stageGap(
                'verification',
                'Verificación',
                'instrumentacion',
                'Email/verificación de cuenta no está en Dashboard ni Timeline MI.',
            ),
            $this->stageSynthetic(
                id: 'purchase',
                label: 'Compra',
                users: $purchaseN,
                previous: $this->sumPrevious([$lab, $pharmacy]),
                truth: 'proxy',
                hint: 'Suma proxy lab + farmacia (Dashboard). No es cohort registro→compra.',
                source: 'Dashboard · lab + pharmacy',
            ),
            $this->stageGap(
                'invoice',
                'Factura',
                'instrumentacion',
                'Facturas existen en Timeline/Event Center por paciente; falta agregación en Dashboard.',
            ),
            $this->stageFromKpi('membership', 'Membresía', $membership, 'Timeline · membership'),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $business
     * @return list<array<string, mixed>>
     */
    private function labsStages($business): array
    {
        $lab = $business->get('lab');
        $abandoned = $business->get('abandoned');

        return [
            $this->stageGap('search', 'Búsqueda', 'instrumentacion', 'Intención de búsqueda lab no instrumentada en MI.'),
            $this->stageFromKpi(
                'cart',
                'Carrito',
                $abandoned,
                'Dashboard · tag abandono (proxy de carrito en riesgo)',
                note: 'Volumen de abandonos tagged; no es “añadidos a carrito”.',
            ),
            $this->stageFromKpi('purchase', 'Compra', $lab, 'Timeline · laboratory_purchase'),
            $this->stageGap('payment', 'Pago', 'instrumentacion', 'Estado de pago agregado no vive en Dashboard MI.'),
            $this->stageGap('schedule', 'Agenda', 'proximamente', 'Agenda de toma no expuesta en Marketing Intelligence.'),
            $this->stageGap('sample', 'Toma', 'instrumentacion', 'Job sample_collected existe en AC; no alimenta Timeline MI ni Dashboard.'),
            $this->stageGap('results', 'Resultados', 'instrumentacion', 'Resultados en Timeline por paciente; sin KPI agregado Dashboard.'),
            $this->stageGap('invoice', 'Factura', 'instrumentacion', 'Factura en Timeline/Event Center por paciente; sin agregación funnel.'),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $business
     * @return list<array<string, mixed>>
     */
    private function pharmacyStages($business): array
    {
        $pharmacy = $business->get('pharmacy');
        $abandoned = $business->get('abandoned');

        return [
            $this->stageGap('search', 'Búsqueda', 'instrumentacion', 'Búsqueda farmacia no instrumentada en MI.'),
            $this->stageFromKpi(
                'cart',
                'Carrito',
                $abandoned,
                'Dashboard · tag abandono (compartido ecommerce)',
                note: 'Abandono tagged global; no segmentado solo farmacia.',
            ),
            $this->stageFromKpi('purchase', 'Compra', $pharmacy, 'Timeline · pharmacy_purchase'),
            $this->stageGap('payment', 'Pago', 'instrumentacion', 'Pago confirmado agregado no disponible en Dashboard.'),
            $this->stageGap('delivery', 'Entrega', 'proximamente', 'Estado de entrega no está en Marketing Intelligence.'),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $health
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $business
     * @return list<array<string, mixed>>
     */
    private function membershipsStages($health, $business): array
    {
        $patients = $health->get('patients');
        $membership = $business->get('membership');

        return [
            $this->stageGap('visit', 'Visita', 'instrumentacion', 'Tráfico web / landings (GA) no conectado a MI.'),
            $this->stageFromHealth('registration', 'Registro', $patients, 'Timeline · registration'),
            $this->stageGap('payment', 'Pago', 'instrumentacion', 'Pago de membresía agregado no está en Dashboard.'),
            $this->stageFromKpi('activation', 'Activación', $membership, 'Timeline · membership / Job activated'),
            $this->stageGap('renewal', 'Renovación', 'instrumentacion', 'Renovaciones / ended no agregados en Dashboard MI.'),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $card
     * @return array<string, mixed>
     */
    private function stageFromHealth(string $id, string $label, ?array $card, string $source): array
    {
        $n = isset($card['value']) ? (int) preg_replace('/[^\d]/', '', (string) $card['value']) : null;

        return $this->stage(
            id: $id,
            label: $label,
            users: $n,
            usersLabel: (string) ($card['value'] ?? self::UNAVAILABLE),
            previous: null,
            conversion: $this->gapMetric('instrumentacion'),
            abandonment: $this->gapMetric('instrumentacion'),
            avgTime: $this->gapMetric('instrumentacion'),
            economicValue: $this->gapMetric('proximamente'),
            vsPrevious: $this->gapMetric('instrumentacion'),
            trend: null,
            truth: $card['truth'] ?? 'proxy',
            hint: $card['hint'] ?? null,
            source: $source,
        );
    }

    /**
     * @param  array<string, mixed>|null  $kpi
     * @return array<string, mixed>
     */
    private function stageFromKpi(
        string $id,
        string $label,
        ?array $kpi,
        string $source,
        ?string $note = null,
    ): array {
        $users = $this->kpiInt($kpi);
        $previous = isset($kpi['previous_formatted'])
            ? (int) preg_replace('/[^\d]/', '', (string) $kpi['previous_formatted'])
            : null;

        return $this->stage(
            id: $id,
            label: $label,
            users: $users,
            usersLabel: (string) ($kpi['value_formatted'] ?? self::UNAVAILABLE),
            previous: $previous,
            conversion: $this->gapMetric('instrumentacion'),
            abandonment: $id === 'cart'
                ? [
                    'value' => (string) ($kpi['value_formatted'] ?? self::UNAVAILABLE),
                    'truth' => $kpi['truth'] ?? 'disponible',
                    'label' => 'Abandonos tagged (no tasa cohort)',
                ]
                : $this->gapMetric('instrumentacion'),
            avgTime: $this->gapMetric('instrumentacion'),
            economicValue: $this->gapMetric('proximamente'),
            vsPrevious: $this->vsPreviousFromKpi($kpi),
            trend: $this->trendFromKpi($kpi),
            truth: $kpi['truth'] ?? 'proxy',
            hint: $note ?? ($kpi['hint'] ?? null),
            source: $source,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function stageSynthetic(
        string $id,
        string $label,
        ?int $users,
        ?int $previous,
        string $truth,
        string $hint,
        string $source,
    ): array {
        $vs = $this->vsPreviousRaw($users, $previous);

        return $this->stage(
            id: $id,
            label: $label,
            users: $users,
            usersLabel: $users === null ? self::UNAVAILABLE : number_format($users),
            previous: $previous,
            conversion: $this->gapMetric('instrumentacion'),
            abandonment: $this->gapMetric('instrumentacion'),
            avgTime: $this->gapMetric('instrumentacion'),
            economicValue: $this->gapMetric('proximamente'),
            vsPrevious: $vs,
            trend: $vs['trend'] ?? null,
            truth: $truth,
            hint: $hint,
            source: $source,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function stageGap(string $id, string $label, string $truth, string $reason): array
    {
        $metric = $this->gapMetric($truth);

        return $this->stage(
            id: $id,
            label: $label,
            users: null,
            usersLabel: $truth === 'proximamente' ? 'Próximamente' : 'Requiere instrumentación',
            previous: null,
            conversion: $metric,
            abandonment: $metric,
            avgTime: $metric,
            economicValue: $this->gapMetric('proximamente'),
            vsPrevious: $metric,
            trend: null,
            truth: $truth,
            hint: $reason,
            source: '—',
        );
    }

    /**
     * @param  array<string, mixed>  $conversion
     * @param  array<string, mixed>  $abandonment
     * @param  array<string, mixed>  $avgTime
     * @param  array<string, mixed>  $economicValue
     * @param  array<string, mixed>  $vsPrevious
     * @return array<string, mixed>
     */
    private function stage(
        string $id,
        string $label,
        ?int $users,
        string $usersLabel,
        ?int $previous,
        array $conversion,
        array $abandonment,
        array $avgTime,
        array $economicValue,
        array $vsPrevious,
        ?string $trend,
        string $truth,
        ?string $hint,
        string $source,
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'users' => $users,
            'users_label' => $usersLabel,
            'previous' => $previous,
            'conversion' => $conversion,
            'abandonment' => $abandonment,
            'avg_time' => $avgTime,
            'economic_value' => $economicValue,
            'vs_previous' => $vsPrevious,
            'trend' => $trend,
            'truth' => $truth,
            'hint' => $hint,
            'source' => $source,
            'width_pct' => null,
        ];
    }

    /**
     * @return array{value: string, truth: string, label?: string}
     */
    private function gapMetric(string $truth): array
    {
        return [
            'value' => $truth === 'proximamente' ? 'Próximamente' : 'Requiere instrumentación',
            'truth' => $truth,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $stages
     * @return list<array<string, mixed>>
     */
    private function withFunnelWidths(array $stages): array
    {
        $max = collect($stages)->max(fn (array $s) => $s['users'] ?? 0) ?: 0;

        return array_map(function (array $stage) use ($max) {
            if ($stage['users'] === null || $max <= 0) {
                $stage['width_pct'] = null;

                return $stage;
            }
            $stage['width_pct'] = max(8, (int) round(($stage['users'] / $max) * 100));

            return $stage;
        }, $stages);
    }

    /**
     * @param  list<array<string, mixed>>  $stages
     * @return list<array<string, mixed>>
     */
    private function buildMetricsTable(array $stages): array
    {
        return array_map(fn (array $s) => [
            'id' => $s['id'],
            'stage' => $s['label'],
            'users' => $s['users_label'],
            'conversion' => $s['conversion']['value'],
            'conversion_truth' => $s['conversion']['truth'],
            'abandonment' => $s['abandonment']['value'],
            'abandonment_truth' => $s['abandonment']['truth'],
            'avg_time' => $s['avg_time']['value'],
            'avg_time_truth' => $s['avg_time']['truth'],
            'economic_value' => $s['economic_value']['value'],
            'economic_value_truth' => $s['economic_value']['truth'],
            'vs_previous' => $s['vs_previous']['value'] ?? self::UNAVAILABLE,
            'vs_previous_truth' => $s['vs_previous']['truth'] ?? 'instrumentacion',
            'trend' => $s['trend'],
            'truth' => $s['truth'],
            'source' => $s['source'],
            'hint' => $s['hint'],
        ], $stages);
    }

    /**
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $health
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $business
     * @param  list<array<string, mixed>>  $stages
     * @return array{insights: list<array<string, mixed>>, recommendations: list<array<string, mixed>>, risks: list<array<string, mixed>>}
     */
    private function buildDecision(
        string $funnelId,
        ActiveCampaignDashboardFilter $filter,
        $health,
        $business,
        array $stages,
    ): array {
        // Reutiliza capa Analytics (misma fuente Dashboard) sin modificar Analytics.
        $analytics = $this->analytics->build($filter);
        $businessDomain = collect($analytics['domains'] ?? [])->firstWhere('id', 'business') ?? [];
        $customersDomain = collect($analytics['domains'] ?? [])->firstWhere('id', 'customers') ?? [];

        $insights = array_values(array_filter(array_merge(
            [
                $this->item(
                    'Embudo «'.self::FUNNELS[$funnelId].'»: volúmenes con dato reutilizan proxies Dashboard; tasas cohort y tiempos entre etapas aún no instrumentados.',
                    'proxy',
                ),
            ],
            $businessDomain['insights'] ?? [],
            $customersDomain['insights'] ?? [],
        )));

        $recommendations = array_values(array_filter(array_merge(
            $businessDomain['recommendations'] ?? [],
            [
                $this->item(
                    'Contrastar pacientes del periodo en CRM / Customer Journey (Timeline por contacto).',
                    'disponible',
                ),
                $this->item(
                    'Revisar Event Center filtrando tipos del mapa Timeline de este funnel.',
                    'disponible',
                ),
            ],
        )));

        $risks = array_values(array_filter(array_merge(
            $businessDomain['risks'] ?? [],
            $customersDomain['risks'] ?? [],
        )));

        $known = collect($stages)->filter(fn (array $s) => $s['users'] !== null)->count();
        $gaps = count($stages) - $known;
        if ($gaps > 0) {
            $insights[] = $this->item(
                "{$gaps} etapa(s) de este funnel aún sin volumen agregado en Dashboard.",
                'instrumentacion',
            );
        }

        $abandoned = $business->get('abandoned');
        if ($this->kpiInt($abandoned) !== null && $this->kpiInt($abandoned) > 0) {
            $risks[] = $this->item(
                'Hay carritos abandonados tagged en el periodo: oportunidad de recuperación (Automation / CRM).',
                'disponible',
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
    private function suggestedActions(string $funnelId): array
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
                'id' => 'journey',
                'label' => 'Abrir Journey',
                'href' => route('admin.activecampaign.customer-journey'),
                'enabled' => true,
            ],
            [
                'id' => 'events',
                'label' => 'Abrir Event Center',
                'href' => route('admin.activecampaign.events'),
                'enabled' => true,
            ],
            [
                'id' => 'crm',
                'label' => 'Ir al CRM',
                'href' => route('admin.activecampaign.contacts'),
                'enabled' => true,
            ],
            [
                'id' => 'automation',
                'label' => 'Automation Center',
                'href' => route('admin.activecampaign.automations'),
                'enabled' => true,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gaps(string $funnelId): array
    {
        $common = [
            [
                'label' => 'Conversión cohort entre etapas',
                'reason' => 'Requiere seguimiento de usuarios a través del embudo, no solo volúmenes independientes.',
                'truth' => 'instrumentacion',
            ],
            [
                'label' => 'Tiempo promedio entre etapas',
                'reason' => 'Necesita timestamps emparejados por paciente/sesión.',
                'truth' => 'instrumentacion',
            ],
            [
                'label' => 'Valor económico (GMV) por etapa',
                'reason' => 'Dashboard MI no expone GMV agregado hoy.',
                'truth' => 'proximamente',
            ],
            [
                'label' => 'Atribución de campaña',
                'reason' => 'GA / Meta Ads aún no conectados al hub.',
                'truth' => 'instrumentacion',
            ],
        ];

        $specific = match ($funnelId) {
            'laboratories' => [
                [
                    'label' => 'Toma de muestra (sample_collected)',
                    'reason' => 'Job AC existe; no está en Timeline MI ni en agregados Dashboard.',
                    'truth' => 'instrumentacion',
                ],
            ],
            'pharmacy' => [
                [
                    'label' => 'Entrega / fulfillment',
                    'reason' => 'Fuera del alcance actual de Marketing Intelligence.',
                    'truth' => 'proximamente',
                ],
            ],
            'memberships' => [
                [
                    'label' => 'Renovación / churn',
                    'reason' => 'Ended/renewal no agregados en Dashboard.',
                    'truth' => 'instrumentacion',
                ],
            ],
            default => [
                [
                    'label' => 'Verificación de cuenta',
                    'reason' => 'No hay señal en Dashboard ni Timeline MI.',
                    'truth' => 'instrumentacion',
                ],
            ],
        };

        return array_merge($common, $specific);
    }

    /**
     * @return list<string>
     */
    private function timelineMap(string $funnelId): array
    {
        return match ($funnelId) {
            'laboratories' => [
                'laboratory_purchase',
                'laboratory_results',
                'invoice',
                'activecampaign_dispatch (ops)',
            ],
            'pharmacy' => [
                'pharmacy_purchase',
                'invoice',
            ],
            'memberships' => [
                'registration',
                'membership',
                'beneficiary_added',
            ],
            default => [
                'registration',
                'laboratory_purchase / pharmacy_purchase',
                'invoice',
                'membership',
                'coupon_assigned',
            ],
        };
    }

    private function funnelDescription(string $funnelId): string
    {
        return match ($funnelId) {
            'laboratories' => 'Recorrido de laboratorio: de intención a resultados y factura. Hoy solo compra (y abandono tagged) tienen volumen agregado.',
            'pharmacy' => 'Recorrido de farmacia ecommerce. Compra tiene proxy Dashboard; resto pendiente de instrumentación.',
            'memberships' => 'Alta y retención de membresías. Activación usa altas del periodo; visita/pago/renovación aún no.',
            default => 'Embudo transversal Famedic: registro → verificación → compra → factura → membresía.',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $stages
     * @return list<array{label: string, value: int|null, truth: string}>
     */
    private function funnelBarSeries(array $stages): array
    {
        return array_map(fn (array $s) => [
            'label' => $s['label'],
            'value' => $s['users'],
            'truth' => $s['truth'],
        ], $stages);
    }

    /**
     * @param  list<array<string, mixed>>  $stages
     * @return list<array{label: string, current: int|null, previous: int|null}>
     */
    private function funnelCompareSeries(array $stages): array
    {
        return collect($stages)
            ->filter(fn (array $s) => $s['users'] !== null)
            ->map(fn (array $s) => [
                'label' => $s['label'],
                'current' => $s['users'],
                'previous' => $s['previous'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $kpi
     */
    private function kpiInt(?array $kpi): ?int
    {
        if (! $kpi) {
            return null;
        }
        if (isset($kpi['value']) && is_numeric($kpi['value'])) {
            return (int) $kpi['value'];
        }
        if (isset($kpi['value_formatted'])) {
            return (int) preg_replace('/[^\d]/', '', (string) $kpi['value_formatted']);
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>|null>  $kpis
     */
    private function sumPrevious(array $kpis): ?int
    {
        $sum = 0;
        $any = false;
        foreach ($kpis as $kpi) {
            if (! $kpi || ! isset($kpi['previous_formatted'])) {
                continue;
            }
            $any = true;
            $sum += (int) preg_replace('/[^\d]/', '', (string) $kpi['previous_formatted']);
        }

        return $any ? $sum : null;
    }

    /**
     * @param  array<string, mixed>|null  $kpi
     * @return array{value: string, truth: string, trend?: string|null}
     */
    private function vsPreviousFromKpi(?array $kpi): array
    {
        if (! $kpi || ($kpi['delta_percent'] ?? null) === null) {
            return $this->gapMetric('instrumentacion');
        }

        $dir = $kpi['delta_direction'] ?? 'flat';
        $pct = $kpi['delta_percent'];
        $sign = $dir === 'down' ? '−' : ($dir === 'up' ? '+' : '');

        return [
            'value' => $dir === 'flat' ? '0%' : "{$sign}{$pct}%",
            'truth' => $kpi['truth'] ?? 'proxy',
            'trend' => $dir === 'flat' ? 'flat' : $dir,
        ];
    }

    /**
     * @return array{value: string, truth: string, trend?: string|null}
     */
    private function vsPreviousRaw(?int $current, ?int $previous): array
    {
        if ($current === null || $previous === null) {
            return $this->gapMetric('instrumentacion');
        }
        if ($previous === 0) {
            return [
                'value' => $current > 0 ? 'Nuevo' : '0%',
                'truth' => 'proxy',
                'trend' => $current > 0 ? 'up' : 'flat',
            ];
        }
        $raw = (($current - $previous) / abs($previous)) * 100;
        $pct = round(abs($raw), 1);
        $dir = $raw > 0.05 ? 'up' : ($raw < -0.05 ? 'down' : 'flat');

        return [
            'value' => $dir === 'flat' ? '0%' : (($dir === 'down' ? '−' : '+').$pct.'%'),
            'truth' => 'proxy',
            'trend' => $dir,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $kpi
     */
    private function trendFromKpi(?array $kpi): ?string
    {
        if (! $kpi) {
            return null;
        }
        $dir = $kpi['delta_direction'] ?? null;

        return in_array($dir, ['up', 'down', 'flat'], true) ? $dir : null;
    }

    /**
     * @param  array<string, mixed>|null  $kpi
     */
    private function deltaSnippet(?array $kpi): ?string
    {
        $vs = $this->vsPreviousFromKpi($kpi);

        return ($vs['truth'] ?? '') === 'instrumentacion' ? null : ($vs['value'] ?? null);
    }

    /**
     * @return array{text: string, truth: string}
     */
    private function item(string $text, string $truth): array
    {
        return ['text' => $text, 'truth' => $truth];
    }
}
