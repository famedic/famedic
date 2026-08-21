<?php

namespace App\Services\CartsDashboard;

use App\Enums\LaboratoryBrand;
use App\Models\Cart;
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
            $daily = $this->repository->dailyCarts($filter);
            $operational = $this->repository->operationalSummary($filter);
            $laboratories = $this->repository->laboratoryRanking($filter);

            return [
                'kpis' => $this->buildExecutiveKpis($current, $previous, $daily),
                'operational_kpis' => $this->buildOperationalKpis($operational),
                'daily' => [
                    'rows' => $daily,
                    'totals' => [
                        'created_count' => (int) collect($daily)->sum('created_count'),
                        'abandoned_count' => (int) collect($daily)->sum('abandoned_count'),
                        'completed_count' => (int) collect($daily)->sum('completed_count'),
                        'created_amount' => round((float) collect($daily)->sum('created_amount'), 2),
                        'abandoned_amount' => round((float) collect($daily)->sum('abandoned_amount'), 2),
                        'completed_amount' => round((float) collect($daily)->sum('completed_amount'), 2),
                    ],
                ],
                'funnel' => [
                    'stages' => $this->repository->checkoutFunnel($filter),
                    'abandonment_by_stage' => $this->repository->abandonmentByStage($filter),
                    'confidence' => [
                        'Carrito y compra se calculan desde carts.',
                        'Checkout iniciado usa drafts, citas o pago correlacionado; si el draft ya no existe, no se inventa la etapa.',
                        'Pago intentado usa solo PaymentAttempt Efevoo correlacionado de forma conservadora.',
                    ],
                ],
                'payments' => $this->repository->paymentAnalytics($filter),
                'appointments' => $this->repository->appointmentAnalytics($filter),
                'contact' => $this->repository->contactAnalytics($filter),
                'laboratories' => $laboratories,
                'laboratory_charts' => $this->buildLaboratoryCharts($laboratories),
                'customer_profile' => $this->repository->customerProfile($filter),
                'ticket_averages' => $this->repository->ticketAverages($filter),
                'top_studies' => $this->repository->topStudies($filter),
                'meta' => [
                    'generated_at' => now(config('app.timezone', 'America/Monterrey'))->format('d/m/Y H:i'),
                    'previous_period' => [
                        'start_date' => $filter->previousStart->timezone(config('app.timezone', 'America/Monterrey'))->toDateString(),
                        'end_date' => $filter->previousEnd->timezone(config('app.timezone', 'America/Monterrey'))->toDateString(),
                    ],
                    'definitions' => [
                        'abandoned' => 'Abandono: sin actividad por mas de '.Cart::ABANDONED_AFTER_MINUTES.' min.',
                        'conversion' => 'Conversion = comprados / (comprados + abandonados).',
                        'cart_amounts' => 'Montos calculados con carts.total; no son ingreso contable.',
                        'payments' => 'Pagos Efevoo correlacionados por cliente, monto y ventana; se excluyen ambiguos.',
                    ],
                    'abandoned_threshold_minutes' => Cart::ABANDONED_AFTER_MINUTES,
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
     * @return list<array<string, mixed>>
     */
    private function buildExecutiveKpis(array $current, array $previous, array $daily): array
    {
        return [
            $this->kpi('created', 'Carritos creados', $current['created'], $previous['created'], 'number', 'blue', $this->spark($daily, 'created_count')),
            $this->kpi('abandoned', 'Abandonados', $current['abandoned'], $previous['abandoned'], 'number', 'red', $this->spark($daily, 'abandoned_count'), true, $this->formatValue($current['abandonment_percent'], 'percent')),
            $this->kpi('completed', 'Comprados', $current['completed'], $previous['completed'], 'number', 'green', $this->spark($daily, 'completed_count')),
            $this->kpi('conversion', 'Conversion', $current['conversion_percent'], $previous['conversion_percent'], 'percent', 'purple', $this->spark($daily, 'completed_count'), false, 'Comprados / (comprados + abandonados)'),
            $this->kpi('abandoned_amount', 'Monto abandonado', $current['abandoned_value'], $previous['abandoned_value'], 'money', 'orange', $this->spark($daily, 'abandoned_amount'), true),
            $this->kpi('completed_amount', 'Monto de carritos comprados', $current['completed_value'], $previous['completed_value'], 'money', 'green', $this->spark($daily, 'completed_amount')),
        ];
    }

    /**
     * @param  array<string, int>  $operational
     * @return list<array<string, mixed>>
     */
    private function buildOperationalKpis(array $operational): array
    {
        return [
            $this->plainKpi('attention_required', 'Atencion requerida', $operational['attention_required'], 'number', 'red', 'operational_bucket=attention'),
            $this->plainKpi('payment_incidents', 'Pagos con incidencia', $operational['payment_incidents'], 'number', 'orange', 'operational_bucket=payment'),
            $this->plainKpi('payment_declined', 'Pago rechazado', $operational['payment_declined'], 'number', 'red', 'payment_status=declined'),
            $this->plainKpi('payment_error', 'Error tecnico', $operational['payment_error'], 'number', 'red', 'payment_status=error'),
            $this->plainKpi('appointments_to_handle', 'Citas por atender', $operational['appointments_to_handle'], 'number', 'purple', 'operational_bucket=appointment'),
            $this->plainKpi('contact_requested', 'Solicitaron contacto', $operational['contact_requested'], 'number', 'blue', 'operational_bucket=contact'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{date: string, label: string, value: float|int}>
     */
    private function spark(array $rows, string $key): array
    {
        return collect($rows)->map(fn (array $row) => [
            'date' => $row['date'],
            'label' => $row['label'],
            'value' => $row[$key] ?? 0,
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

        return array_merge($this->plainKpi($id, $label, $value, $format, $tone, null, $hint), [
            'previous_value' => $previous,
            'previous_formatted' => $this->formatValue($previous, $format),
            'delta_percent' => $delta['percent'],
            'delta_direction' => $delta['direction'],
            'delta_is_positive' => $delta['is_positive'],
            'sparkline' => $sparkline,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function plainKpi(
        string $id,
        string $label,
        int|float|null $value,
        string $format,
        string $tone,
        ?string $monitorQuery = null,
        ?string $hint = null,
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'value' => $value,
            'value_formatted' => $this->formatValue($value, $format),
            'format' => $format,
            'tone' => $tone,
            'hint' => $hint,
            'monitor_query' => $monitorQuery,
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
            return '--';
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
        return [
            'created' => collect($laboratories)->sortByDesc('carts_count')->map(fn (array $row) => [
                'label' => $row['brand_label'],
                'value' => $row['carts_count'],
            ])->values()->all(),
            'abandoned' => collect($laboratories)->sortByDesc('abandoned_count')->map(fn (array $row) => [
                'label' => $row['brand_label'],
                'value' => $row['abandoned_count'],
            ])->values()->all(),
            'completed' => collect($laboratories)->sortByDesc('completed_count')->map(fn (array $row) => [
                'label' => $row['brand_label'],
                'value' => $row['completed_count'],
            ])->values()->all(),
            'conversion' => collect($laboratories)->sortByDesc('conversion_percent')->map(fn (array $row) => [
                'label' => $row['brand_label'],
                'value' => $row['conversion_percent'],
            ])->values()->all(),
            'abandoned_value' => collect($laboratories)->sortByDesc('abandoned_value')->map(fn (array $row) => [
                'label' => $row['brand_label'],
                'value' => $row['abandoned_value'],
            ])->values()->all(),
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
