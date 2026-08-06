<?php

namespace App\Services\ActiveCampaign;

use App\Enums\MedicalSubscriptionType;
use App\Models\FamilyAccount;
use App\Models\MedicalAttentionSubscription;
use App\Support\ActiveCampaign\ActiveCampaignDashboardFilter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Membership Intelligence — consola ejecutiva de membresías.
 * Fuente: MedicalAttentionSubscription (+ FamilyAccount). Señal cruzada Dashboard/Analytics.
 * No modifica módulos aprobados.
 */
class ActiveCampaignMembershipIntelligenceService
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
     * @return array<string, mixed>
     */
    public function build(Request $request): array
    {
        $filter = ActiveCampaignDashboardFilter::fromRequest($request);

        $resolver = function () use ($filter) {
            $kpis = $this->buildExecutiveKpis($filter);
            $distribution = $this->buildDistribution($filter);
            $overview = $this->dashboard->buildOverview($filter);
            $decision = $this->buildDecision($filter, $kpis, $distribution, $overview);

            return [
                'filters' => $filter->toArray(),
                'summary' => $kpis['cards'],
                'kpis' => $kpis['business'],
                'distribution' => $distribution,
                'insights' => $decision['insights'],
                'recommendations' => $decision['recommendations'],
                'risks' => $decision['risks'],
                'suggested_actions' => $this->suggestedActions(),
                'gaps' => $this->gaps(),
                'meta' => [
                    ...($overview['meta'] ?? []),
                    'purpose' => 'Analizar el comportamiento completo del negocio de membresías Famedic.',
                    'source_of_truth' => 'MedicalAttentionSubscription · FamilyAccount · señal cruzada Dashboard/Analytics · Timeline membership/beneficiary_added',
                    'note' => 'Tipos reales: trial/regular/institutional/family_member. Mensual/Semestral/Anual se infieren por duración cuando aplica.',
                    'timeline_map' => ['membership', 'beneficiary_added'],
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
     * @return array<string, mixed>
     */
    public function buildCharts(Request $request): array
    {
        $filter = ActiveCampaignDashboardFilter::fromRequest($request);

        $resolver = fn () => [
            'by_day' => $this->seriesByDay($filter),
            'by_week' => $this->seriesByWeek($filter),
            'by_month' => $this->seriesByMonth($filter),
            'by_type' => $this->seriesByType($filter),
            'by_city' => $this->seriesByCity($filter),
        ];

        if ($filter->bustCache) {
            Cache::forget($this->cacheKey($filter, 'charts'));
        }

        return Cache::remember($this->cacheKey($filter, 'charts'), now()->addMinutes(5), $resolver);
    }

    private function cacheKey(ActiveCampaignDashboardFilter $filter, string $suffix): string
    {
        return 'mi-membership-intel:v1:'.sha1(json_encode($filter->toArray()).'|'.$suffix);
    }

    /**
     * @return array{cards: list<array<string, mixed>>, business: list<array<string, mixed>>}
     */
    private function buildExecutiveKpis(ActiveCampaignDashboardFilter $filter): array
    {
        $now = now();
        $current = $this->periodStats($filter->start, $filter->end, $now);
        $previous = $this->periodStats($filter->previousStart, $filter->previousEnd, $filter->previousEnd);

        $activeNow = (int) MedicalAttentionSubscription::query()
            ->whereNull('deleted_at')
            ->where('type', '!=', MedicalSubscriptionType::FAMILY_MEMBER->value)
            ->active($now)
            ->count();

        $activePrevEnd = (int) MedicalAttentionSubscription::query()
            ->whereNull('deleted_at')
            ->where('type', '!=', MedicalSubscriptionType::FAMILY_MEMBER->value)
            ->active($filter->previousEnd)
            ->count();

        $retention = null;
        $retentionTruth = 'proxy';
        $cohortBase = $current['ended'] + $current['still_active_from_period'];
        if ($cohortBase > 0) {
            $retention = round(100 * $current['still_active_from_period'] / $cohortBase, 1);
        }

        $avgPermanence = $current['avg_permanence_days'];
        $avgPermanencePrev = $previous['avg_permanence_days'];

        $cards = [
            $this->metricCard(
                'activas',
                'Membresías activas',
                number_format($activeNow),
                'disponible',
                'lime',
                'Stock vigente ahora (excluye family_member)',
                $this->deltaFloat((float) $activeNow, (float) $activePrevEnd),
            ),
            $this->metricCard(
                'nuevas',
                'Nuevas',
                number_format($current['nuevas']),
                'proxy',
                'sky',
                'Altas por created_at (titulares; excluye family_member)',
                $this->deltaFloat((float) $current['nuevas'], (float) $previous['nuevas']),
            ),
            $this->metricCard(
                'renovaciones',
                'Renovaciones',
                'Requiere instrumentación',
                'instrumentacion',
                'amber',
                'No hay flag/evento de renovación en el dominio',
                null,
            ),
            $this->metricCard(
                'canceladas',
                'Canceladas / terminadas',
                number_format($current['cancelled'] + $current['ended']),
                'proxy',
                'red',
                'Soft-delete + end_date en periodo (no churn voluntario explícito)',
                $this->deltaFloat(
                    (float) ($current['cancelled'] + $current['ended']),
                    (float) ($previous['cancelled'] + $previous['ended']),
                    true,
                ),
            ),
            $this->metricCard(
                'ingresos',
                'Ingresos',
                $this->money($current['revenue_cents']),
                'disponible',
                'lime',
                'SUM(price_cents) altas pagadas del periodo (trial/familia = 0)',
                $this->deltaFloat((float) $current['revenue_cents'], (float) $previous['revenue_cents']),
            ),
            $this->metricCard(
                'beneficiarios',
                'Beneficiarios',
                number_format($current['beneficiarios']),
                'disponible',
                'sky',
                'FamilyAccount creados + altas type=family_member',
                $this->deltaFloat((float) $current['beneficiarios'], (float) $previous['beneficiarios']),
            ),
            $this->metricCard(
                'retencion',
                'Retención',
                $retention === null ? '—' : $retention.'%',
                $retentionTruth,
                'amber',
                'Proxy: altas del periodo aún activas / (activas + terminadas del cohort)',
                null,
            ),
            $this->metricCard(
                'permanencia',
                'Permanencia promedio',
                $avgPermanence === null ? '—' : number_format($avgPermanence, 0).' días',
                'proxy',
                'sky',
                'AVG(días entre start_date y min(end_date, hoy)) en altas del periodo',
                $avgPermanence !== null && $avgPermanencePrev !== null
                    ? $this->deltaFloat($avgPermanence, $avgPermanencePrev)
                    : null,
            ),
        ];

        $business = [
            $this->kpi('activas', 'Activas', $activeNow, $activePrevEnd, 'green', 'disponible', 'stock', false),
            $this->kpi('nuevas', 'Nuevas', $current['nuevas'], $previous['nuevas'], 'blue', 'proxy', 'altas', false),
            $this->kpi('ingresos', 'Ingresos', $current['revenue_cents'] / 100, $previous['revenue_cents'] / 100, 'green', 'disponible', 'MXN', true),
            $this->kpi('beneficiarios', 'Beneficiarios', $current['beneficiarios'], $previous['beneficiarios'], 'blue', 'disponible', 'altas', false),
        ];

        return ['cards' => $cards, 'business' => $business];
    }

    /**
     * @return array{
     *     nuevas: int,
     *     revenue_cents: int,
     *     cancelled: int,
     *     ended: int,
     *     still_active_from_period: int,
     *     beneficiarios: int,
     *     avg_permanence_days: float|null
     * }
     */
    private function periodStats(Carbon $start, Carbon $end, Carbon $asOf): array
    {
        $startS = $start->toDateTimeString();
        $endS = $end->toDateTimeString();
        $asOfDate = $asOf->toDateString();

        $titulares = MedicalAttentionSubscription::query()
            ->toBase()
            ->whereNull('deleted_at')
            ->where('type', '!=', MedicalSubscriptionType::FAMILY_MEMBER->value)
            ->whereBetween('created_at', [$startS, $endS])
            ->selectRaw('COUNT(*) as nuevas')
            ->selectRaw('COALESCE(SUM(price_cents), 0) as revenue_cents')
            ->selectRaw('SUM(CASE WHEN end_date >= ? AND start_date <= ? THEN 1 ELSE 0 END) as still_active', [$asOfDate, $asOfDate])
            ->selectRaw('SUM(CASE WHEN end_date < ? THEN 1 ELSE 0 END) as ended', [$asOfDate])
            ->selectRaw('AVG(DATEDIFF(LEAST(end_date, ?), start_date)) as avg_days', [$asOfDate])
            ->first();

        $cancelled = (int) MedicalAttentionSubscription::query()
            ->withTrashed()
            ->toBase()
            ->whereNotNull('deleted_at')
            ->where('type', '!=', MedicalSubscriptionType::FAMILY_MEMBER->value)
            ->whereBetween('deleted_at', [$startS, $endS])
            ->count();

        $familyMembers = (int) MedicalAttentionSubscription::query()
            ->toBase()
            ->whereNull('deleted_at')
            ->where('type', MedicalSubscriptionType::FAMILY_MEMBER->value)
            ->whereBetween('created_at', [$startS, $endS])
            ->count();

        $familyAccounts = (int) FamilyAccount::query()
            ->toBase()
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$startS, $endS])
            ->count();

        $avg = $titulares->avg_days !== null ? round((float) $titulares->avg_days, 1) : null;

        return [
            'nuevas' => (int) ($titulares->nuevas ?? 0),
            'revenue_cents' => (int) ($titulares->revenue_cents ?? 0),
            'cancelled' => $cancelled,
            'ended' => (int) ($titulares->ended ?? 0),
            'still_active_from_period' => (int) ($titulares->still_active ?? 0),
            'beneficiarios' => max($familyMembers, $familyAccounts),
            'avg_permanence_days' => $avg,
        ];
    }

    /**
     * @return array{by_enum: list<array<string, mixed>>, by_plan: list<array<string, mixed>>}
     */
    private function buildDistribution(ActiveCampaignDashboardFilter $filter): array
    {
        $startS = $filter->start->toDateTimeString();
        $endS = $filter->end->toDateTimeString();

        $byEnum = MedicalAttentionSubscription::query()
            ->toBase()
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$startS, $endS])
            ->selectRaw('type')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(price_cents), 0) as revenue_cents')
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        $enumRows = collect(MedicalSubscriptionType::cases())->map(function (MedicalSubscriptionType $type) use ($byEnum) {
            $row = $byEnum->get($type->value);
            $total = (int) ($row->total ?? 0);
            $revenue = (int) ($row->revenue_cents ?? 0);

            return [
                'id' => $type->value,
                'label' => $type->label(),
                'total' => $total,
                'total_label' => number_format($total),
                'revenue_label' => $this->money($revenue),
                'truth' => 'disponible',
                'hint' => 'Tipo nativo MedicalSubscriptionType',
            ];
        })->filter(fn (array $r) => $r['total'] > 0)->values()->all();

        $durationRows = MedicalAttentionSubscription::query()
            ->toBase()
            ->whereNull('deleted_at')
            ->where('type', '!=', MedicalSubscriptionType::FAMILY_MEMBER->value)
            ->whereBetween('created_at', [$startS, $endS])
            ->selectRaw('id, type, start_date, end_date, price_cents, DATEDIFF(end_date, start_date) as duration_days')
            ->get();

        $buckets = [
            'mensual' => ['label' => 'Mensual', 'truth' => 'proxy', 'hint' => 'Inferido: duración ≈ 25–45 días', 'total' => 0, 'revenue' => 0],
            'semestral' => ['label' => 'Semestral', 'truth' => 'proxy', 'hint' => 'Inferido: duración ≈ 150–200 días', 'total' => 0, 'revenue' => 0],
            'anual' => ['label' => 'Anual', 'truth' => 'proxy', 'hint' => 'Inferido: duración ≈ 300–400 días (regular típico)', 'total' => 0, 'revenue' => 0],
            'corporativa' => ['label' => 'Corporativa', 'truth' => 'proxy', 'hint' => 'Mapeo: type=institutional', 'total' => 0, 'revenue' => 0],
            'otros' => ['label' => 'Otros planes', 'truth' => 'disponible', 'hint' => 'Trial u otras duraciones no clasificadas', 'total' => 0, 'revenue' => 0],
        ];

        foreach ($durationRows as $row) {
            $days = (int) ($row->duration_days ?? 0);
            $cents = (int) ($row->price_cents ?? 0);
            $type = (string) $row->type;

            if ($type === MedicalSubscriptionType::INSTITUTIONAL->value) {
                $key = 'corporativa';
            } elseif ($days >= 25 && $days <= 45) {
                $key = 'mensual';
            } elseif ($days >= 150 && $days <= 200) {
                $key = 'semestral';
            } elseif ($days >= 300 && $days <= 400) {
                $key = 'anual';
            } else {
                $key = 'otros';
            }

            $buckets[$key]['total']++;
            $buckets[$key]['revenue'] += $cents;
        }

        // Mensual/Semestral sin filas reales → dejar visible con 0 y truth instrumentacion si nunca hubo
        $planRows = [];
        foreach ($buckets as $id => $bucket) {
            $truth = $bucket['truth'];
            if (in_array($id, ['mensual', 'semestral'], true) && $bucket['total'] === 0) {
                $truth = 'instrumentacion';
                $bucket['hint'] = 'No hay planes mensuales/semestrales modelados; sin altas con esa duración.';
            }

            $planRows[] = [
                'id' => $id,
                'label' => $bucket['label'],
                'total' => $bucket['total'],
                'total_label' => $bucket['total'] === 0 && $truth === 'instrumentacion'
                    ? 'Requiere instrumentación'
                    : number_format($bucket['total']),
                'revenue_label' => $this->money($bucket['revenue']),
                'truth' => $truth,
                'hint' => $bucket['hint'],
            ];
        }

        return [
            'by_enum' => $enumRows,
            'by_plan' => $planRows,
        ];
    }

    /**
     * @return list<array{label: string, altas: int, ingresos: float}>
     */
    private function seriesByDay(ActiveCampaignDashboardFilter $filter): array
    {
        return MedicalAttentionSubscription::query()
            ->toBase()
            ->whereNull('deleted_at')
            ->where('type', '!=', MedicalSubscriptionType::FAMILY_MEMBER->value)
            ->whereBetween('created_at', [
                $filter->start->toDateTimeString(),
                $filter->end->toDateTimeString(),
            ])
            ->selectRaw('DATE(created_at) as day_key')
            ->selectRaw('COUNT(*) as altas')
            ->selectRaw('COALESCE(SUM(price_cents), 0) as revenue_cents')
            ->groupBy('day_key')
            ->orderBy('day_key')
            ->get()
            ->map(function ($row) {
                $day = Carbon::parse((string) $row->day_key, self::TZ);

                return [
                    'label' => $day->format('d/m'),
                    'altas' => (int) $row->altas,
                    'ingresos' => round(((int) $row->revenue_cents) / 100, 2),
                ];
            })
            ->all();
    }

    /**
     * @return list<array{label: string, altas: int, ingresos: float}>
     */
    private function seriesByWeek(ActiveCampaignDashboardFilter $filter): array
    {
        return $this->bucketSeries($filter, '%x-W%v');
    }

    /**
     * @return list<array{label: string, altas: int, ingresos: float}>
     */
    private function seriesByMonth(ActiveCampaignDashboardFilter $filter): array
    {
        return $this->bucketSeries($filter, '%Y-%m');
    }

    /**
     * @return list<array{label: string, altas: int, ingresos: float}>
     */
    private function bucketSeries(ActiveCampaignDashboardFilter $filter, string $format): array
    {
        return MedicalAttentionSubscription::query()
            ->toBase()
            ->whereNull('deleted_at')
            ->where('type', '!=', MedicalSubscriptionType::FAMILY_MEMBER->value)
            ->whereBetween('created_at', [
                $filter->start->toDateTimeString(),
                $filter->end->toDateTimeString(),
            ])
            ->selectRaw("DATE_FORMAT(created_at, '{$format}') as bucket")
            ->selectRaw('COUNT(*) as altas')
            ->selectRaw('COALESCE(SUM(price_cents), 0) as revenue_cents')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->bucket,
                'altas' => (int) $row->altas,
                'ingresos' => round(((int) $row->revenue_cents) / 100, 2),
            ])
            ->all();
    }

    /**
     * @return list<array{label: string, altas: int, ingresos: float}>
     */
    private function seriesByType(ActiveCampaignDashboardFilter $filter): array
    {
        return MedicalAttentionSubscription::query()
            ->toBase()
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [
                $filter->start->toDateTimeString(),
                $filter->end->toDateTimeString(),
            ])
            ->selectRaw('type')
            ->selectRaw('COUNT(*) as altas')
            ->selectRaw('COALESCE(SUM(price_cents), 0) as revenue_cents')
            ->groupBy('type')
            ->orderByDesc('altas')
            ->get()
            ->map(function ($row) {
                $type = MedicalSubscriptionType::tryFrom((string) $row->type);

                return [
                    'label' => $type?->label() ?? (string) $row->type,
                    'altas' => (int) $row->altas,
                    'ingresos' => round(((int) $row->revenue_cents) / 100, 2),
                ];
            })
            ->all();
    }

    /**
     * Ciudad vía addresses del customer (proxy; cobertura incompleta).
     *
     * @return list<array{label: string, altas: int, ingresos: float}>
     */
    private function seriesByCity(ActiveCampaignDashboardFilter $filter): array
    {
        $startS = $filter->start->toDateTimeString();
        $endS = $filter->end->toDateTimeString();

        $inner = DB::table('medical_attention_subscriptions as mas')
            ->join('addresses as a', function ($join) {
                $join->on('a.customer_id', '=', 'mas.customer_id')
                    ->whereNull('a.deleted_at');
            })
            ->whereNull('mas.deleted_at')
            ->where('mas.type', '!=', MedicalSubscriptionType::FAMILY_MEMBER->value)
            ->whereBetween('mas.created_at', [$startS, $endS])
            ->whereNotNull('a.city')
            ->where('a.city', '!=', '')
            ->groupBy('mas.id', 'mas.price_cents')
            ->selectRaw('mas.id, mas.price_cents, MIN(a.city) as city');

        return DB::query()
            ->fromSub($inner, 'x')
            ->selectRaw('city')
            ->selectRaw('COUNT(*) as altas')
            ->selectRaw('COALESCE(SUM(price_cents), 0) as revenue_cents')
            ->groupBy('city')
            ->orderByDesc('altas')
            ->limit(12)
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->city,
                'altas' => (int) $row->altas,
                'ingresos' => round(((int) $row->revenue_cents) / 100, 2),
            ])
            ->all();
    }

    /**
     * @param  array{cards: list<array<string, mixed>>}  $kpis
     * @param  array{by_enum: list<array<string, mixed>>, by_plan: list<array<string, mixed>>}  $distribution
     * @param  array<string, mixed>  $overview
     * @return array{insights: list<array<string, mixed>>, recommendations: list<array<string, mixed>>, risks: list<array<string, mixed>>}
     */
    private function buildDecision(
        ActiveCampaignDashboardFilter $filter,
        array $kpis,
        array $distribution,
        array $overview,
    ): array {
        $analytics = $this->analytics->build($filter);
        $businessDomain = collect($analytics['domains'] ?? [])->firstWhere('id', 'business') ?? [];

        $insights = [
            $this->item(
                'Membership Intelligence agrega MedicalAttentionSubscription reales (activas, altas, ingresos, beneficiarios).',
                'disponible',
            ),
        ];

        $topEnum = collect($distribution['by_enum'])->sortByDesc('total')->first();
        if ($topEnum) {
            $insights[] = $this->item(
                "Tipo dominante en altas del periodo: {$topEnum['label']} ({$topEnum['total_label']}).",
                'disponible',
            );
        }

        foreach ($businessDomain['insights'] ?? [] as $item) {
            $insights[] = $item;
        }

        $dashMembership = collect($overview['business'] ?? [])->firstWhere('id', 'membership');
        if ($dashMembership) {
            $insights[] = $this->item(
                'Señal cruzada Dashboard KPI Membresías (proxy altas): '.$dashMembership['value_formatted'].'.',
                'proxy',
            );
        }

        $recommendations = [
            $this->item(
                'Contrastar altas con Customer Journey / Timeline (membership, beneficiary_added).',
                'disponible',
            ),
            $this->item(
                'Instrumentar renovaciones explícitas para cerrar el gap del funnel de membresías.',
                'instrumentacion',
            ),
        ];
        foreach ($businessDomain['recommendations'] ?? [] as $item) {
            $recommendations[] = $item;
        }

        $risks = [];
        $cancelCard = collect($kpis['cards'])->firstWhere('id', 'canceladas');
        if ($cancelCard && preg_replace('/[^\d]/', '', (string) $cancelCard['value']) !== '0') {
            $risks[] = $this->item(
                'Hay terminaciones/cancelaciones (proxy) en el periodo: revisar churn y end_date acortado.',
                'proxy',
            );
        }
        $retencion = collect($kpis['cards'])->firstWhere('id', 'retencion');
        if ($retencion && str_contains((string) $retencion['value'], '%')) {
            $pct = (float) str_replace('%', '', (string) $retencion['value']);
            if ($pct < 50) {
                $risks[] = $this->item(
                    "Retención proxy del cohort de altas bajo 50% ({$retencion['value']}).",
                    'proxy',
                );
            }
        }
        foreach ($businessDomain['risks'] ?? [] as $item) {
            $risks[] = $item;
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
                'label' => 'Funnel Membresías',
                'href' => route('admin.activecampaign.funnels', ['funnel' => 'memberships']),
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
                'label' => 'Renovaciones explícitas',
                'reason' => 'Sin flag/evento de renovación; parent_subscription_id es vínculo familiar, no renew.',
                'truth' => 'instrumentacion',
            ],
            [
                'label' => 'Planes Mensual / Semestral',
                'reason' => 'El dominio modela trial/regular/institutional/family_member; periodos cortos no están SKU.',
                'truth' => 'instrumentacion',
            ],
            [
                'label' => 'Cancelación voluntaria',
                'reason' => 'Solo soft-delete y fin de vigencia; no hay motivo de churn.',
                'truth' => 'instrumentacion',
            ],
            [
                'label' => 'Ciudad de membresía',
                'reason' => 'Ciudad vía addresses del customer (cobertura incompleta / multi-address).',
                'truth' => 'proxy',
            ],
            [
                'label' => 'Sync ActiveCampaign confirmado',
                'reason' => 'Jobs activate/end existen; sin KPI agregado en MI.',
                'truth' => 'proximamente',
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
            'source' => 'medical_attention_subscriptions',
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
