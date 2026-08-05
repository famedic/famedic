<?php

namespace App\Services\CustomerIntelligence;

use App\Data\StatesMexico;
use App\DTOs\CustomerIntelligence\CohortRetentionRowData;
use App\Support\CustomerIntelligence\CohortsFilter;
use Illuminate\Support\Facades\Cache;

class CohortsAnalyticsService
{
    public function __construct(
        private CohortsRepository $repository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(CohortsFilter $filter): array
    {
        $resolver = function () use ($filter) {
            $current = $this->repository->summaryKpis($filter);
            $previous = $this->repository->summaryKpis($filter, $filter->previousStart, $filter->previousEnd);
            $heatmapRows = $this->repository->cohortHeatmap($filter);
            $curves = $this->repository->retentionCurves($heatmapRows);
            $bySource = $this->repository->retentionBySource($filter);
            $repeatLadder = $this->repository->repeatPurchaseLadder($filter);
            $daysBetween = $this->repository->daysBetweenPurchases($filter);
            $churn = $this->repository->churnBuckets($filter);
            $ltv = $this->repository->ltvBreakdown($filter);
            $segments = $this->repository->segmentationCards($filter);

            return [
                'kpis' => $this->buildKpis($current, $previous),
                'heatmap' => array_map(fn (CohortRetentionRowData $r) => $r->toArray(), $heatmapRows),
                'curves' => $curves,
                'source_comparison' => $bySource,
                'repeat_ladder' => $repeatLadder,
                'days_between' => $daysBetween,
                'churn' => $this->formatChurn($churn),
                'ltv' => $ltv,
                'segments' => $segments,
                'ai_insights' => $this->buildAiInsights($current, $bySource, $churn, $ltv, $repeatLadder),
                'automations' => $this->buildAutomations(),
                'meta' => [
                    'generated_at' => now('America/Monterrey')->format('d/m/Y H:i'),
                    'previous_period' => [
                        'start_date' => $filter->previousStart->timezone('America/Monterrey')->toDateString(),
                        'end_date' => $filter->previousEnd->timezone('America/Monterrey')->toDateString(),
                    ],
                    'definitions' => [
                        'cohort' => 'Clientes agrupados por mes de registro (America/Monterrey).',
                        'retention_week' => 'Semana N = % del cohort con al menos una compra en esa semana desde el registro. W0 = 100%.',
                        'retention_nd' => '% de compradores con 1ª compra ≥ N días atrás que repitieron dentro de N días.',
                        'churn' => 'Clientes con última compra anterior al umbral (30/60/90/180/365 días).',
                        'source' => 'Fuente proxy: orgánico, referidos, Odessa, familiar (sin UTM nativo).',
                    ],
                    'data_gaps' => [
                        'Campañas Google/Meta Ads requieren UTM o sync publicitario.',
                        'Dispositivo y edad fina dependen de tracking de sesión / perfil completo.',
                        'LTV por laboratorio específico se puede enriquecer con brand en purchases.',
                    ],
                ],
            ];
        };

        if ($filter->bustCache) {
            Cache::forget($filter->cacheKey());
        }

        return Cache::remember($filter->cacheKey(), now()->addMinutes(5), $resolver);
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @return list<array<string, mixed>>
     */
    private function buildKpis(array $current, array $previous): array
    {
        return [
            $this->kpi('registered', 'Clientes nuevos', $current['new_customers'], $previous['new_customers'], 'number', 'blue'),
            $this->kpi('purchased', 'Clientes recurrentes', $current['recurrent'], $previous['recurrent'], 'number', 'green'),
            $this->kpi('recovered', 'Clientes retenidos', $current['retained'], $previous['retained'], 'number', 'purple'),
            $this->kpi('abandonment', 'Clientes perdidos', $current['lost'], $previous['lost'], 'number', 'red', higherIsWorse: true, hint: 'Churn 30 días'),
            $this->kpi('retention_30', 'Retención 30 días', $current['retention_30'], $previous['retention_30'], 'percent', 'green'),
            $this->kpi('retention_60', 'Retención 60 días', $current['retention_60'], $previous['retention_60'], 'percent', 'blue'),
            $this->kpi('retention_90', 'Retención 90 días', $current['retention_90'], $previous['retention_90'], 'percent', 'slate'),
            $this->kpi('avg_ltv', 'LTV promedio', $current['avg_ltv'], $previous['avg_ltv'], 'money', 'purple'),
            $this->kpi('time_to_purchase', 'Tiempo entre compras', $current['avg_days_between'], $previous['avg_days_between'], 'days', 'orange'),
            $this->kpi('conversion', 'Repeat Purchase Rate', $current['repeat_rate'], $previous['repeat_rate'], 'percent', 'green'),
        ];
    }

    /**
     * @param  array<string, int>  $churn
     * @return list<array{key: string, label: string, count: int}>
     */
    private function formatChurn(array $churn): array
    {
        $labels = [
            '30' => '30 días',
            '60' => '60 días',
            '90' => '90 días',
            '180' => '180 días',
            '365' => '365 días',
        ];

        return collect($labels)->map(fn (string $label, string $key) => [
            'key' => $key,
            'label' => $label,
            'count' => (int) ($churn[$key] ?? 0),
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $kpis
     * @param  list<array<string, mixed>>  $bySource
     * @param  array<string, int>  $churn
     * @param  list<array<string, mixed>>  $ltv
     * @param  list<array<string, mixed>>  $ladder
     * @return array{headline: string, findings: list<string>, recommendations: list<string>}
     */
    private function buildAiInsights(array $kpis, array $bySource, array $churn, array $ltv, array $ladder): array
    {
        $bestSource = collect($bySource)->sortByDesc('retention_30')->first();
        $worstSource = collect($bySource)->sortBy('retention_30')->first();
        $bestCity = collect($ltv)->where('dimension', 'city')->sortByDesc('avg_ltv')->first();
        $bestState = collect($ltv)->where('dimension', 'state')->sortByDesc('avg_ltv')->first();
        $membership = collect($ltv)->firstWhere('key', 'membership');
        $second = collect($ladder)->firstWhere('key', 'second');

        $findings = [];

        if ($bestSource && $bestSource['retention_30'] !== null) {
            $findings[] = "La fuente {$bestSource['label']} lidera retención a 30 días con {$bestSource['retention_30']}%.";
        }
        if ($worstSource && $bestSource && ($worstSource['key'] ?? null) !== ($bestSource['key'] ?? null)) {
            $findings[] = "{$worstSource['label']} muestra la retención más baja del comparador — revisar calidad de tráfico.";
        }
        if ($kpis['repeat_rate'] !== null) {
            $findings[] = "El Repeat Purchase Rate del cohort es {$kpis['repeat_rate']}%.";
        }
        if ($second) {
            $findings[] = "El {$second['percent']}% de quienes compran llega a una segunda compra.";
        }
        if ($membership) {
            $findings[] = "Los clientes con canal membresía aportan LTV promedio de \$".number_format($membership['avg_ltv'], 0).' MXN.';
        }
        if ($bestCity) {
            $findings[] = "{$bestCity['label']} destaca por LTV promedio de \$".number_format($bestCity['avg_ltv'], 0).' MXN.';
        }
        if ($bestState) {
            $findings[] = 'El estado con mejor LTV es '.$bestState['label'].'.';
        }
        $findings[] = 'Hay '.number_format($churn['90'] ?? 0).' clientes en churn de 90+ días candidatos a win-back.';

        if ($findings === []) {
            $findings[] = 'Aún no hay suficiente volumen de compras para insights de retención.';
        }

        return [
            'headline' => 'AI Retention Insights',
            'findings' => $findings,
            'recommendations' => [
                'Crear campaña win-back a churn 30–60 días',
                'Priorizar presupuesto en la fuente con mejor retención 30d',
                'Incentivar segunda compra entre días 8 y 15',
                'Activar cupón de recurrencia para laboratorios de alto LTV',
                'Segmentar ActiveCampaign por cohort mensual',
            ],
        ];
    }

    /**
     * @return list<array{id: string, label: string, description: string, icon: string, enabled: bool}>
     */
    private function buildAutomations(): array
    {
        return [
            ['id' => 'segment', 'label' => 'Crear segmento', 'description' => 'Exportar cohort / churn', 'icon' => 'queue', 'enabled' => false],
            ['id' => 'campaign', 'label' => 'Enviar campaña', 'description' => 'Nurturing de retención', 'icon' => 'megaphone', 'enabled' => false],
            ['id' => 'whatsapp', 'label' => 'Enviar WhatsApp', 'description' => 'Win-back 30 días', 'icon' => 'chat', 'enabled' => false],
            ['id' => 'coupon', 'label' => 'Crear cupón', 'description' => 'Incentivo 2ª compra', 'icon' => 'ticket', 'enabled' => false],
            ['id' => 'automation', 'label' => 'Automatización ActiveCampaign', 'description' => 'Flujo por cohort', 'icon' => 'bolt', 'enabled' => false],
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
            'days' => number_format((float) $value, 1).' días',
            'money' => '$'.number_format((float) $value, 0).' MXN',
            default => number_format((float) $value),
        };
    }
}
