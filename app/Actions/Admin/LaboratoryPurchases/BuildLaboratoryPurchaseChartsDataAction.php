<?php

namespace App\Actions\Admin\LaboratoryPurchases;

use App\Actions\BuildDailyChartDataAction;
use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BuildLaboratoryPurchaseChartsDataAction
{
    public function __construct(
        private BuildDailyChartDataAction $buildDailyChartDataAction
    ) {}

    public function __invoke(array $filters, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        [$start, $end, $filterStart, $filterEnd] = $this->resolveDateRange($filters, $startDate, $endDate);

        $baseQuery = LaboratoryPurchase::query()->filter($filters);

        $purchasesLite = (clone $baseQuery)
            ->select(['id', 'created_at', 'total_cents', 'brand', 'deleted_at', 'results', 'gda_consecutivo'])
            ->get();

        $daily = $this->buildDailySeries($purchasesLite, $start, $end);

        return [
            'period' => [
                'start_date' => $filterStart,
                'end_date' => $filterEnd,
            ],
            'summary' => $this->buildSummary($baseQuery, $purchasesLite),
            'dailyOrders' => $daily['orders'],
            'dailyRevenue' => ($this->buildDailyChartDataAction)($purchasesLite, $start, $end),
            'paymentMethods' => $this->buildPaymentMethods($filters),
            'resultsStatus' => $this->buildResultsStatus($baseQuery),
            'invoicePending' => $this->buildInvoicePending($baseQuery),
            'cancelled' => $this->buildCancelledSeries($purchasesLite, $start, $end),
            'byBrand' => $this->buildByBrand($purchasesLite),
            'notifications' => $this->buildNotifications($baseQuery),
        ];
    }

    private function resolveDateRange(array $filters, ?Carbon $startDate, ?Carbon $endDate): array
    {
        $tz = 'America/Monterrey';

        $filterStart = $filters['start_date'] ?? null;
        $filterEnd = $filters['end_date'] ?? null;

        if (! $filterStart && ! $filterEnd) {
            $filterStart = Carbon::now($tz)->subDays(30)->toDateString();
            $filterEnd = Carbon::now($tz)->toDateString();
        }

        $start = $startDate
            ? $startDate->copy()->timezone($tz)->startOfDay()
            : Carbon::parse($filterStart, $tz)->startOfDay();

        $end = $endDate
            ? $endDate->copy()->timezone($tz)->endOfDay()
            : Carbon::parse($filterEnd, $tz)->endOfDay();

        return [$start, $end, $filterStart, $filterEnd];
    }

    private function buildSummary(Builder $baseQuery, Collection $purchasesLite): array
    {
        $total = $purchasesLite->count();
        $cancelled = $purchasesLite->whereNotNull('deleted_at')->count();
        $active = $total - $cancelled;

        return [
            'total' => $total,
            'active' => $active,
            'cancelled' => $cancelled,
            'with_results' => $this->countWithResults($baseQuery),
            'without_results' => $this->countWithoutResults($baseQuery),
            'invoice_pending' => $this->countInvoicePending($baseQuery),
            'with_sample_notification' => $this->countWithSampleNotification($baseQuery),
            'with_results_notification' => $this->countWithResultsNotification($baseQuery),
            'with_both_notifications' => $this->countWithBothNotifications($baseQuery),
        ];
    }

    private function buildDailySeries(Collection $purchases, Carbon $start, Carbon $end): array
    {
        $hasMultipleYears = $start->year !== $end->year;

        $ordersByDay = $purchases->groupBy(function ($purchase) use ($start) {
            return localizedDate($purchase->created_at)
                ->timezone('America/Monterrey')
                ->toDateString();
        })->map->count();

        $cancelledByDay = $purchases
            ->whereNotNull('deleted_at')
            ->groupBy(function ($purchase) {
                return localizedDate($purchase->created_at)
                    ->timezone('America/Monterrey')
                    ->toDateString();
            })
            ->map->count();

        $orderPoints = [];
        $cancelledPoints = [];

        foreach (CarbonPeriod::create($start->toDateString(), $end->toDateString()) as $day) {
            $key = $day->toDateString();
            $label = $hasMultipleYears
                ? Carbon::parse($key, 'America/Monterrey')->isoFormat('MMM D, Y')
                : Carbon::parse($key, 'America/Monterrey')->isoFormat('MMM D');

            $orderPoints[] = [
                'date' => $label,
                'value' => (int) ($ordersByDay[$key] ?? 0),
            ];

            $cancelledPoints[] = [
                'date' => $label,
                'value' => (int) ($cancelledByDay[$key] ?? 0),
            ];
        }

        return [
            'orders' => [
                'dataPoints' => $orderPoints,
                'total' => $purchases->count(),
            ],
        ];
    }

    private function buildPaymentMethods(array $filters): array
    {
        $rows = LaboratoryPurchase::query()
            ->select('laboratory_purchases.id')
            ->filter($filters)
            ->join('transactionables', function ($join) {
                $join->on('transactionables.transactionable_id', '=', 'laboratory_purchases.id')
                    ->where('transactionables.transactionable_type', LaboratoryPurchase::class);
            })
            ->join('transactions', 'transactions.id', '=', 'transactionables.transaction_id')
            ->select('transactions.payment_method', DB::raw('COUNT(DISTINCT laboratory_purchases.id) as count'))
            ->groupBy('transactions.payment_method')
            ->orderByDesc('count')
            ->get();

        return $rows->map(fn ($row) => [
            'key' => $row->payment_method,
            'label' => $this->paymentMethodLabel($row->payment_method),
            'value' => (int) $row->count,
        ])->values()->all();
    }

    private function buildResultsStatus(Builder $baseQuery): array
    {
        $with = $this->countWithResults($baseQuery);
        $without = $this->countWithoutResults($baseQuery);

        return [
            ['key' => 'with', 'label' => 'Con resultados', 'value' => $with, 'color' => '#22c55e'],
            ['key' => 'without', 'label' => 'Sin resultados', 'value' => $without, 'color' => '#94a3b8'],
        ];
    }

    private function buildInvoicePending(Builder $baseQuery): array
    {
        $pending = $this->countInvoicePending($baseQuery);
        $requested = (clone $baseQuery)->whereHas('invoiceRequest')->count();
        $uploaded = max(0, $requested - $pending);

        return [
            'count' => $pending,
            'requested_total' => $requested,
            'segments' => [
                ['key' => 'pending', 'label' => 'Solicitada, sin subir', 'value' => $pending, 'color' => '#f59e0b'],
                ['key' => 'uploaded', 'label' => 'Solicitada y cargada', 'value' => $uploaded, 'color' => '#22c55e'],
            ],
        ];
    }

    private function buildCancelledSeries(Collection $purchases, Carbon $start, Carbon $end): array
    {
        $hasMultipleYears = $start->year !== $end->year;
        $cancelled = $purchases->whereNotNull('deleted_at');

        $byDay = $cancelled->groupBy(function ($purchase) {
            return localizedDate($purchase->created_at)
                ->timezone('America/Monterrey')
                ->toDateString();
        })->map->count();

        $dataPoints = [];
        foreach (CarbonPeriod::create($start->toDateString(), $end->toDateString()) as $day) {
            $key = $day->toDateString();
            $dataPoints[] = [
                'date' => $hasMultipleYears
                    ? Carbon::parse($key, 'America/Monterrey')->isoFormat('MMM D, Y')
                    : Carbon::parse($key, 'America/Monterrey')->isoFormat('MMM D'),
                'value' => (int) ($byDay[$key] ?? 0),
            ];
        }

        return [
            'total' => $cancelled->count(),
            'dataPoints' => $dataPoints,
        ];
    }

    private function buildByBrand(Collection $purchases): array
    {
        $activePurchases = $purchases->whereNull('deleted_at');

        return collect(LaboratoryBrand::cases())
            ->map(function (LaboratoryBrand $brand) use ($activePurchases) {
                $count = $activePurchases->where('brand', $brand)->count();

                return [
                    'key' => $brand->value,
                    'label' => $brand->label(),
                    'value' => $count,
                ];
            })
            ->filter(fn ($row) => $row['value'] > 0)
            ->sortByDesc('value')
            ->values()
            ->all();
    }

    private function buildNotifications(Builder $baseQuery): array
    {
        $withSample = $this->countWithSampleNotification($baseQuery);
        $withResults = $this->countWithResultsNotification($baseQuery);
        $withBoth = $this->countWithBothNotifications($baseQuery);
        $neither = (clone $baseQuery)
            ->whereNotNull('gda_consecutivo')
            ->whereDoesntHave('laboratoryNotifications', fn (Builder $q) => $this->applySampleNotificationScope($q))
            ->whereDoesntHave('laboratoryNotifications', fn (Builder $q) => $this->applyResultsNotificationScope($q))
            ->count();

        return [
            [
                'key' => 'sample',
                'label' => 'Toma de muestra (notif. GDA)',
                'value' => $withSample,
                'color' => '#0ea5e9',
            ],
            [
                'key' => 'results_auto',
                'label' => 'Resultados automáticos (notif. GDA)',
                'value' => $withResults,
                'color' => '#22c55e',
            ],
            [
                'key' => 'both',
                'label' => 'Muestra y resultados (GDA)',
                'value' => $withBoth,
                'color' => '#8b5cf6',
            ],
            [
                'key' => 'neither',
                'label' => 'Sin notificaciones GDA',
                'value' => $neither,
                'color' => '#94a3b8',
            ],
        ];
    }

    private function countWithResults(Builder $baseQuery): int
    {
        return (clone $baseQuery)->where(function (Builder $query) {
            $query->where(function (Builder $query) {
                $query->whereNotNull('results')->where('results', '!=', '');
            })->orWhereHas('laboratoryNotifications', fn (Builder $q) => $this->applyResultsNotificationScope($q));
        })->count();
    }

    private function countWithoutResults(Builder $baseQuery): int
    {
        return (clone $baseQuery)
            ->whereNull('deleted_at')
            ->where(function (Builder $query) {
                $query->where(function (Builder $query) {
                    $query->whereNull('results')->orWhere('results', '');
                })->whereDoesntHave('laboratoryNotifications', fn (Builder $q) => $this->applyResultsNotificationScope($q));
            })
            ->count();
    }

    private function countInvoicePending(Builder $baseQuery): int
    {
        return (clone $baseQuery)
            ->whereHas('invoiceRequest')
            ->whereDoesntHave('invoice')
            ->count();
    }

    private function countWithSampleNotification(Builder $baseQuery): int
    {
        return (clone $baseQuery)
            ->whereNotNull('gda_consecutivo')
            ->whereHas('laboratoryNotifications', fn (Builder $q) => $this->applySampleNotificationScope($q))
            ->count();
    }

    private function countWithResultsNotification(Builder $baseQuery): int
    {
        return (clone $baseQuery)
            ->whereNotNull('gda_consecutivo')
            ->whereHas('laboratoryNotifications', fn (Builder $q) => $this->applyResultsNotificationScope($q))
            ->count();
    }

    private function countWithBothNotifications(Builder $baseQuery): int
    {
        return (clone $baseQuery)
            ->whereNotNull('gda_consecutivo')
            ->whereHas('laboratoryNotifications', fn (Builder $q) => $this->applySampleNotificationScope($q))
            ->whereHas('laboratoryNotifications', fn (Builder $q) => $this->applyResultsNotificationScope($q))
            ->count();
    }

    private function applySampleNotificationScope(Builder $query): void
    {
        $query->where(function (Builder $query) {
            $query->where('notification_type', LaboratoryNotification::TYPE_SAMPLE_COLLECTION)
                ->orWhere('lineanegocio', LaboratoryNotification::LINEA_NEGOCIO_SAMPLE);
        });
    }

    private function applyResultsNotificationScope(Builder $query): void
    {
        $query->where(function (Builder $query) {
            $query->where('notification_type', LaboratoryNotification::TYPE_RESULTS)
                ->orWhere('lineanegocio', LaboratoryNotification::LINEA_NEGOCIO_RESULTS);
        });
    }

    private function paymentMethodLabel(?string $method): string
    {
        return match ($method) {
            'odessa' => 'Caja de ahorro',
            'stripe' => 'Tarjeta',
            'efevoopay' => 'Efevoo Pay',
            'paypal' => 'PayPal',
            default => $method ? ucfirst($method) : 'Sin método',
        };
    }
}
