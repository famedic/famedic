<?php

namespace App\Services\CustomerIntelligence;

use App\Data\StatesMexico;
use App\Support\CustomerIntelligence\CustomerHealthFilter;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

class CustomerHealthAnalyticsService
{
    public function __construct(
        private CustomerHealthRepository $repository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(CustomerHealthFilter $filter): array
    {
        $resolver = function () use ($filter) {
            $analysis = $this->repository->analyzeCohort($filter);
            $previous = $this->repository->analyzeCohort(
                new CustomerHealthFilter(
                    start: $filter->previousStart,
                    end: $filter->previousEnd,
                    previousStart: $filter->previousStart,
                    previousEnd: $filter->previousEnd,
                    startLocal: $filter->startLocal->copy()->subDays(
                        max(1, $filter->startLocal->diffInDays($filter->endLocal) + 1)
                    ),
                    endLocal: $filter->startLocal->copy()->subSecond(),
                    search: $filter->search,
                    accountType: $filter->accountType,
                    source: $filter->source,
                    state: $filter->state,
                    city: $filter->city,
                    healthBand: null,
                    segment: null,
                    sort: $filter->sort,
                    tab: $filter->tab,
                    page: null,
                ),
                400
            );

            return [
                'kpis' => $this->buildKpis($analysis, $previous),
                'gauge' => [
                    'average' => $analysis['average'],
                    'sample_size' => $analysis['sample_size'],
                ],
                'histogram' => $analysis['histogram'],
                'scatter' => $this->buildScatter($analysis['scored']),
                'by_city' => $analysis['by_city'],
                'by_source' => $analysis['by_source'],
                'by_channel' => $analysis['by_channel'],
                'bands' => $this->formatBands($analysis['bands']),
                'segments' => $this->formatSegments($analysis['segments']),
                'predictive_averages' => $this->predictiveAverages($analysis['scored']),
                'recommendations' => $this->buildRecommendationCards($analysis['scored']),
                'ai_insights' => $this->buildAiInsights($analysis),
                'automations' => $this->buildAutomations(),
                'scored' => $analysis['scored'],
                'meta' => [
                    'generated_at' => now('America/Monterrey')->format('d/m/Y H:i'),
                    'sample_size' => $analysis['sample_size'],
                    'previous_period' => [
                        'start_date' => $filter->previousStart->timezone('America/Monterrey')->toDateString(),
                        'end_date' => $filter->previousEnd->timezone('America/Monterrey')->toDateString(),
                    ],
                    'definitions' => [
                        'health_score' => 'Score 0–100 basado en verificación, actividad, carrito, checkout, compras, membresía y recencia.',
                        'bands' => 'Excelente 81–100 · Bueno 61–80 · En Riesgo 41–60 · Crítico 21–40 · Perdido 0–20.',
                        'probabilities' => 'Heurísticas predictivas derivadas del health score y señales de engagement (preparadas para modelo ML).',
                        'sample' => 'Análisis sobre hasta 800 clientes del cohort filtrado para rendimiento.',
                    ],
                    'data_gaps' => [
                        'Aperturas/clics de email y respuestas WhatsApp requieren sync ActiveCampaign.',
                        'Login real y dispositivo requieren event stream de sesiones.',
                    ],
                ],
            ];
        };

        if ($filter->bustCache) {
            Cache::forget($filter->cacheKey());
        }

        $dashboard = Cache::remember($filter->cacheKey(), now()->addMinutes(5), $resolver);

        $dashboard['customers'] = $this->paginateScored(
            $dashboard['scored'] ?? [],
            $filter,
        );

        unset($dashboard['scored']);

        return $dashboard;
    }

    /**
     * @param  list<array<string, mixed>>  $scored
     */
    private function paginateScored(array $scored, CustomerHealthFilter $filter, int $perPage = 20): LengthAwarePaginator
    {
        $rows = collect($scored);

        $rows = match ($filter->sort) {
            'health_asc' => $rows->sortBy('health_score'),
            'ltv_desc' => $rows->sortByDesc('ltv'),
            'churn_desc' => $rows->sortByDesc(fn ($r) => $r['probabilities']['churn'] ?? 0),
            'recent' => $rows->sortBy('days_since_activity'),
            default => $rows->sortByDesc('health_score'),
        };

        $rows = $rows->values();
        $page = max(1, $filter->page ?? 1);

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @return list<array<string, mixed>>
     */
    private function buildKpis(array $current, array $previous): array
    {
        $bands = $current['bands'];
        $prevBands = $previous['bands'];

        return [
            $this->kpi('avg_health', 'Promedio Health Score', $current['average'], $previous['average'], 'number', 'purple'),
            $this->kpi('excellent', 'Clientes Excelente', $bands['excellent'] ?? 0, $prevBands['excellent'] ?? 0, 'number', 'green'),
            $this->kpi('good', 'Clientes Bueno', $bands['good'] ?? 0, $prevBands['good'] ?? 0, 'number', 'blue'),
            $this->kpi('at_risk', 'Clientes En Riesgo', ($bands['at_risk'] ?? 0) + ($bands['critical'] ?? 0), ($prevBands['at_risk'] ?? 0) + ($prevBands['critical'] ?? 0), 'number', 'orange', higherIsWorse: true),
            $this->kpi('lost', 'Clientes Perdidos', $bands['lost'] ?? 0, $prevBands['lost'] ?? 0, 'number', 'red', higherIsWorse: true),
            $this->kpi('city_health', 'Health avg ciudad top', $current['by_city'][0]['average'] ?? null, null, 'number', 'slate', hint: $current['by_city'][0]['label'] ?? null),
            $this->kpi('source_health', 'Health avg fuente top', $current['by_source'][0]['average'] ?? null, null, 'number', 'blue', hint: $current['by_source'][0]['label'] ?? null),
            $this->kpi('channel_health', 'Health avg canal top', collect($current['by_channel'])->filter(fn ($r) => $r['average'] !== null)->sortByDesc('average')->first()['average'] ?? null, null, 'number', 'green', hint: collect($current['by_channel'])->filter(fn ($r) => $r['average'] !== null)->sortByDesc('average')->first()['label'] ?? null),
        ];
    }

    /**
     * @param  array<string, int>  $bands
     * @return list<array{key: string, label: string, count: int, tone: string}>
     */
    private function formatBands(array $bands): array
    {
        $map = [
            'excellent' => ['label' => 'Excelente', 'tone' => 'green'],
            'good' => ['label' => 'Bueno', 'tone' => 'blue'],
            'at_risk' => ['label' => 'En Riesgo', 'tone' => 'orange'],
            'critical' => ['label' => 'Crítico', 'tone' => 'red'],
            'lost' => ['label' => 'Perdido', 'tone' => 'slate'],
        ];

        return collect($map)->map(fn (array $meta, string $key) => [
            'key' => $key,
            'label' => $meta['label'],
            'tone' => $meta['tone'],
            'count' => (int) ($bands[$key] ?? 0),
        ])->values()->all();
    }

    /**
     * @param  array<string, int>  $segments
     * @return list<array{id: string, label: string, count: int, description: string}>
     */
    private function formatSegments(array $segments): array
    {
        $labels = [
            'premium' => ['Clientes Premium', 'Alto score y buen LTV'],
            'vip' => ['Clientes VIP', 'Máxima recurrencia y valor'],
            'high_value' => ['Alto Valor', 'LTV elevado'],
            'dormant' => ['Dormidos', 'Sin compra ni engagement'],
            'recoverable' => ['Recuperables', 'Carrito/checkout sin compra'],
            'lost' => ['Perdidos', 'Compraron y se enfriaron'],
            'high_risk' => ['Alto Riesgo', 'Health bajo'],
            'next_purchase' => ['Próxima Compra', 'Alta probabilidad de convertir'],
            'high_conversion' => ['Alta Conversión', 'Perfil saludable'],
        ];

        return collect($labels)->map(fn (array $meta, string $id) => [
            'id' => $id,
            'label' => $meta[0],
            'description' => $meta[1],
            'count' => (int) ($segments[$id] ?? 0),
        ])->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $scored
     * @return list<array{id: int, name: string, health_score: int, ltv: float, band: string, persona: string}>
     */
    private function buildScatter(array $scored): array
    {
        return collect($scored)
            ->sortByDesc('ltv')
            ->take(120)
            ->map(fn (array $row) => [
                'id' => $row['id'],
                'name' => $row['name'],
                'health_score' => $row['health_score'],
                'ltv' => $row['ltv'],
                'band' => $row['band'],
                'persona' => $row['persona'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $scored
     * @return array<string, float|null>
     */
    private function predictiveAverages(array $scored): array
    {
        if ($scored === []) {
            return [
                'purchase' => null,
                'churn' => null,
                'email_response' => null,
                'whatsapp_response' => null,
                'membership' => null,
                'laboratory' => null,
                'pharmacy' => null,
            ];
        }

        $keys = ['purchase', 'churn', 'email_response', 'whatsapp_response', 'membership', 'laboratory', 'pharmacy'];
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = round(collect($scored)->avg(fn ($r) => $r['probabilities'][$key] ?? 0), 1);
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $scored
     * @return list<array{id: string, title: string, detail: string, tone: string}>
     */
    private function buildRecommendationCards(array $scored): array
    {
        $highBuy = collect($scored)->first(fn ($r) => ($r['probabilities']['purchase'] ?? 0) >= 70);
        $highChurn = collect($scored)->sortByDesc(fn ($r) => $r['probabilities']['churn'] ?? 0)->first();
        $highLtv = collect($scored)->sortByDesc('ltv')->first();
        $recoverable = collect($scored)->firstWhere('persona', 'recoverable');

        $cards = [];
        if ($highBuy) {
            $cards[] = [
                'id' => 'coupon',
                'title' => 'Alta probabilidad de compra',
                'detail' => "{$highBuy['name']} · enviar cupón del 10%",
                'tone' => 'green',
            ];
        }
        if ($highChurn) {
            $cards[] = [
                'id' => 'whatsapp',
                'title' => 'Alto riesgo de abandono',
                'detail' => "{$highChurn['name']} · enviar WhatsApp",
                'tone' => 'orange',
            ];
        }
        if ($highLtv && ($highLtv['ltv'] ?? 0) > 0) {
            $cards[] = [
                'id' => 'executive',
                'title' => 'Alto Lifetime Value',
                'detail' => "{$highLtv['name']} · asignar ejecutivo",
                'tone' => 'purple',
            ];
        }
        if ($recoverable) {
            $cards[] = [
                'id' => 'campaign',
                'title' => 'Cliente recuperable',
                'detail' => "{$recoverable['name']} · crear campaña",
                'tone' => 'blue',
            ];
        }

        return $cards;
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array{headline: string, findings: list<string>, recommendations: list<string>}
     */
    private function buildAiInsights(array $analysis): array
    {
        $avg = $analysis['average'];
        $excellent = $analysis['bands']['excellent'] ?? 0;
        $lost = $analysis['bands']['lost'] ?? 0;
        $sample = max(1, $analysis['sample_size']);
        $excellentPct = round(($excellent / $sample) * 100, 1);
        $topSource = $analysis['by_source'][0] ?? null;
        $topCity = $analysis['by_city'][0] ?? null;

        $findings = [
            "El Health Score promedio del cohort es {$avg}.",
            "Los clientes Excelente representan el {$excellentPct}% de la muestra.",
            'Los clientes con Health Score > 80 suelen concentrar la mayor parte del LTV y la conversión.',
            'Los clientes con score < 30 concentran abandono pre-checkout y nula recurrencia.',
        ];

        if ($topSource) {
            $findings[] = "La fuente {$topSource['label']} tiene el mejor health promedio ({$topSource['average']}).";
        }
        if ($topCity) {
            $findings[] = "{$topCity['label']} lidera health promedio geográfico ({$topCity['average']}).";
        }
        $findings[] = 'Hay '.number_format($lost).' clientes en banda Perdido candidatos a win-back.';

        return [
            'headline' => 'AI Health Insights',
            'findings' => $findings,
            'recommendations' => [
                'Lanzar campaña de recuperación a bandas En Riesgo y Crítico',
                'Priorizar cupones en personas next_purchase / recoverable',
                'Asignar ejecutivo a VIP y Premium',
                'Activar WhatsApp en alto churn + teléfono verificado',
                'Nutrir Excelente con upsell de membresía / laboratorio',
            ],
        ];
    }

    /**
     * @return list<array{id: string, label: string, description: string, icon: string, enabled: bool}>
     */
    private function buildAutomations(): array
    {
        return [
            ['id' => 'email', 'label' => 'Enviar Email', 'description' => 'Secuencia según health band', 'icon' => 'envelope', 'enabled' => false],
            ['id' => 'whatsapp', 'label' => 'Enviar WhatsApp', 'description' => 'Reactivación alto churn', 'icon' => 'chat', 'enabled' => false],
            ['id' => 'coupon', 'label' => 'Crear Cupón', 'description' => 'Incentivo 10% próxima compra', 'icon' => 'ticket', 'enabled' => false],
            ['id' => 'segment', 'label' => 'Crear Segmento', 'description' => 'Exportar banda / persona', 'icon' => 'queue', 'enabled' => false],
            ['id' => 'tag', 'label' => 'Agregar Tag', 'description' => 'Tag health-band AC', 'icon' => 'tag', 'enabled' => false],
            ['id' => 'list', 'label' => 'Mover Lista', 'description' => 'Lista ActiveCampaign', 'icon' => 'queue', 'enabled' => false],
            ['id' => 'automation', 'label' => 'Programar Automatización', 'description' => 'Flujo por score', 'icon' => 'bolt', 'enabled' => false],
            ['id' => 'executive', 'label' => 'Asignar Ejecutivo', 'description' => 'VIP / Premium', 'icon' => 'megaphone', 'enabled' => false],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function stateOptions(): array
    {
        return collect(StatesMexico::todos())
            ->map(fn (string $label, string $value) => [
                'value' => $value,
                'label' => $label,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function cityOptions(): array
    {
        return $this->repository->availableCities()
            ->map(fn (string $city) => [
                'value' => $city,
                'label' => $city,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function kpi(
        string $id,
        string $label,
        int|float|null $value,
        int|float|null $previous,
        string $format,
        string $tone,
        bool $higherIsWorse = false,
        ?string $hint = null,
    ): array {
        $delta = $this->delta($value, $previous, $higherIsWorse);

        return [
            'id' => $id,
            'label' => $label,
            'value' => $value,
            'value_formatted' => $this->formatValue($value, $format),
            'previous_value' => $previous,
            'previous_formatted' => $this->formatValue($previous, $format),
            'format' => $format,
            'tone' => $tone,
            'delta_percent' => $delta['percent'],
            'delta_direction' => $delta['direction'],
            'delta_is_positive' => $delta['is_positive'],
            'sparkline' => [],
            'hint' => $hint,
        ];
    }

    /**
     * @return array{percent: float|null, direction: string, is_positive: bool|null}
     */
    private function delta(int|float|null $current, int|float|null $previous, bool $higherIsWorse): array
    {
        if ($current === null || $previous === null) {
            return ['percent' => null, 'direction' => 'flat', 'is_positive' => null];
        }
        if ((float) $previous === 0.0) {
            if ((float) $current === 0.0) {
                return ['percent' => 0.0, 'direction' => 'flat', 'is_positive' => null];
            }
            $direction = $current > 0 ? 'up' : 'down';

            return [
                'percent' => 100.0,
                'direction' => $direction,
                'is_positive' => $higherIsWorse ? $direction === 'down' : $direction === 'up',
            ];
        }

        $raw = (($current - $previous) / abs($previous)) * 100;
        $direction = $raw > 0.05 ? 'up' : ($raw < -0.05 ? 'down' : 'flat');

        return [
            'percent' => round(abs($raw), 1),
            'direction' => $direction,
            'is_positive' => match ($direction) {
                'flat' => null,
                'up' => ! $higherIsWorse,
                'down' => $higherIsWorse,
            },
        ];
    }

    private function formatValue(int|float|null $value, string $format): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($format) {
            'percent' => number_format((float) $value, 1).'%',
            default => number_format((float) $value, $format === 'number' && fmod((float) $value, 1) !== 0.0 ? 1 : 0),
        };
    }
}
