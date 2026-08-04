<?php

namespace App\Services\CartsDashboard;

use App\Enums\LaboratoryBrand;
use App\Support\CartsDashboard\CartsDashboardFilter;
use Illuminate\Support\Facades\Cache;

class CartsAnalyticsService
{
    public function __construct(
        private CartsDashboardRepository $repository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(CartsDashboardFilter $filter): array
    {
        $resolver = function () use ($filter) {
            $current = $this->repository->summary($filter, $filter->start, $filter->end);
            $previous = $this->repository->summary($filter, $filter->previousStart, $filter->previousEnd);
            $daily = $this->repository->dailySalesVsAbandoned($filter);
            $createdSpark = $this->buildCreatedSparkline($filter, $daily);
            $laboratories = $this->repository->laboratoryRanking($filter);
            $topStudies = $this->repository->topStudies($filter);
            $revenueDistribution = $this->repository->revenueDistribution($filter);

            return [
                'kpis' => $this->buildKpis($current, $previous, $daily, $createdSpark),
                'sales_vs_abandoned' => [
                    'daily' => $daily,
                    'totals' => [
                        'sold_amount' => round(collect($daily)->sum('sold_amount'), 2),
                        'abandoned_amount' => round(collect($daily)->sum('abandoned_amount'), 2),
                        'sold_count' => (int) collect($daily)->sum('sold_count'),
                        'abandoned_count' => (int) collect($daily)->sum('abandoned_count'),
                    ],
                ],
                'trends' => [
                    'sales_count' => collect($daily)->map(fn (array $row) => [
                        'date' => $row['date'],
                        'label' => $row['label'],
                        'value' => $row['sold_count'],
                    ])->values()->all(),
                    'abandoned_count' => collect($daily)->map(fn (array $row) => [
                        'date' => $row['date'],
                        'label' => $row['label'],
                        'value' => $row['abandoned_count'],
                    ])->values()->all(),
                    'sold_amount' => collect($daily)->map(fn (array $row) => [
                        'date' => $row['date'],
                        'label' => $row['label'],
                        'value' => $row['sold_amount'],
                    ])->values()->all(),
                    'abandoned_amount' => collect($daily)->map(fn (array $row) => [
                        'date' => $row['date'],
                        'label' => $row['label'],
                        'value' => $row['abandoned_amount'],
                    ])->values()->all(),
                ],
                'laboratories' => $laboratories,
                'laboratory_charts' => $this->buildLaboratoryCharts($laboratories),
                'top_studies' => $topStudies,
                'revenue_distribution' => $revenueDistribution,
                'meta' => [
                    'generated_at' => now('America/Monterrey')->format('d/m/Y H:i'),
                    'previous_period' => [
                        'start_date' => $filter->previousStart->timezone('America/Monterrey')->toDateString(),
                        'end_date' => $filter->previousEnd->timezone('America/Monterrey')->toDateString(),
                    ],
                    'definitions' => [
                        'abandoned' => 'Carrito activo sin compra con ≥ 30 min sin actividad.',
                        'recovered' => 'Estimado: compra posterior a tag de abandono ActiveCampaign.',
                        'revenue' => 'Suma de pedidos de laboratorio/farmacia en el periodo.',
                        'lost_value' => 'Suma del total snapshot de carritos abandonados en el periodo.',
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
     * @param  list<array<string, mixed>>  $daily
     * @param  list<array<string, mixed>>  $createdSpark
     * @return list<array<string, mixed>>
     */
    private function buildKpis(array $current, array $previous, array $daily, array $createdSpark): array
    {
        $soldSpark = collect($daily)->map(fn (array $row) => [
            'date' => $row['date'],
            'label' => $row['label'],
            'value' => $row['sold_count'],
        ])->take(-14)->values()->all();

        $abandonedCountSpark = collect($daily)->map(fn (array $row) => [
            'date' => $row['date'],
            'label' => $row['label'],
            'value' => $row['abandoned_count'],
        ])->take(-14)->values()->all();

        $soldAmountSpark = collect($daily)->map(fn (array $row) => [
            'date' => $row['date'],
            'label' => $row['label'],
            'value' => $row['sold_amount'],
        ])->take(-14)->values()->all();

        $abandonedAmountSpark = collect($daily)->map(fn (array $row) => [
            'date' => $row['date'],
            'label' => $row['label'],
            'value' => $row['abandoned_amount'],
        ])->take(-14)->values()->all();

        return [
            $this->kpi(
                id: 'created',
                label: 'Carritos creados',
                value: $current['created'],
                previous: $previous['created'],
                format: 'number',
                tone: 'blue',
                sparkline: $createdSpark,
            ),
            $this->kpi(
                id: 'abandoned',
                label: 'Carritos abandonados',
                value: $current['abandoned'],
                previous: $previous['abandoned'],
                format: 'number',
                tone: 'red',
                sparkline: $abandonedCountSpark,
                higherIsWorse: true,
            ),
            $this->kpi(
                id: 'recovered',
                label: 'Carritos recuperados',
                value: $current['recovered'],
                previous: $previous['recovered'],
                format: 'number',
                tone: 'green',
                sparkline: $soldSpark,
                hint: 'Estimado (tag AC + compra)',
            ),
            $this->kpi(
                id: 'sales',
                label: 'Ventas realizadas',
                value: $current['completed'],
                previous: $previous['completed'],
                format: 'number',
                tone: 'green',
                sparkline: $soldSpark,
            ),
            $this->kpi(
                id: 'lost_value',
                label: 'Valor potencial perdido',
                value: $current['abandoned_value'],
                previous: $previous['abandoned_value'],
                format: 'money',
                tone: 'red',
                sparkline: $abandonedAmountSpark,
                higherIsWorse: true,
            ),
            $this->kpi(
                id: 'recovered_value',
                label: 'Valor recuperado',
                value: $current['recovered_value'],
                previous: $previous['recovered_value'],
                format: 'money',
                tone: 'green',
                sparkline: $soldAmountSpark,
                hint: 'Estimado',
            ),
            $this->kpi(
                id: 'conversion',
                label: 'Conversión',
                value: $current['conversion_percent'],
                previous: $previous['conversion_percent'],
                format: 'percent',
                tone: 'purple',
                sparkline: $soldSpark,
            ),
            $this->kpi(
                id: 'avg_ticket',
                label: 'Ticket promedio',
                value: $current['avg_ticket'],
                previous: $previous['avg_ticket'],
                format: 'money',
                tone: 'blue',
                sparkline: $soldAmountSpark,
            ),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $daily
     * @return list<array<string, mixed>>
     */
    private function buildCreatedSparkline(CartsDashboardFilter $filter, array $daily): array
    {
        $counts = $this->repository->dailyCreatedCounts($filter);

        return collect($daily)->map(fn (array $row) => [
            'date' => $row['date'],
            'label' => $row['label'],
            'value' => (int) ($counts[$row['date']] ?? 0),
        ])->take(-14)->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $sparkline
     * @return array<string, mixed>
     */
    private function kpi(
        string $id,
        string $label,
        int|float|null $value,
        int|float|null $previous,
        string $format,
        string $tone,
        array $sparkline,
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
            'sparkline' => $sparkline,
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
            $isPositive = $higherIsWorse ? $direction === 'down' : $direction === 'up';

            return ['percent' => 100.0, 'direction' => $direction, 'is_positive' => $isPositive];
        }

        $raw = (($current - $previous) / abs($previous)) * 100;
        $direction = $raw > 0.05 ? 'up' : ($raw < -0.05 ? 'down' : 'flat');
        $isPositive = match ($direction) {
            'flat' => null,
            'up' => ! $higherIsWorse,
            'down' => $higherIsWorse,
        };

        return [
            'percent' => round(abs($raw), 1),
            'direction' => $direction,
            'is_positive' => $isPositive,
        ];
    }

    private function formatValue(int|float|null $value, string $format): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($format) {
            'money' => formattedPrice((float) $value),
            'percent' => number_format((float) $value, 1).'%',
            default => number_format((float) $value),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $laboratories
     * @return array<string, list<array{label: string, value: float|int|null}>>
     */
    private function buildLaboratoryCharts(array $laboratories): array
    {
        $sortedBySales = collect($laboratories)->sortByDesc('sales_count')->values();
        $sortedByAbandoned = collect($laboratories)->sortByDesc('abandoned_count')->values();
        $sortedByConversion = collect($laboratories)->sortByDesc('conversion_percent')->values();
        $sortedByRevenue = collect($laboratories)->sortByDesc('revenue')->values();
        $sortedByLost = collect($laboratories)->sortByDesc('abandoned_value')->values();

        return [
            'sales' => $sortedBySales->map(fn (array $row) => [
                'label' => $row['brand_label'],
                'value' => $row['sales_count'],
            ])->all(),
            'abandoned' => $sortedByAbandoned->map(fn (array $row) => [
                'label' => $row['brand_label'],
                'value' => $row['abandoned_count'],
            ])->all(),
            'conversion' => $sortedByConversion->map(fn (array $row) => [
                'label' => $row['brand_label'],
                'value' => $row['conversion_percent'],
            ])->all(),
            'revenue' => $sortedByRevenue->map(fn (array $row) => [
                'label' => $row['brand_label'],
                'value' => $row['revenue'],
            ])->all(),
            'lost_value' => $sortedByLost->map(fn (array $row) => [
                'label' => $row['brand_label'],
                'value' => $row['abandoned_value'],
            ])->all(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function brandOptions(): array
    {
        return collect(LaboratoryBrand::cases())
            ->map(fn (LaboratoryBrand $brand) => [
                'value' => $brand->value,
                'label' => $brand->label(),
            ])
            ->values()
            ->all();
    }
}
