<?php

namespace App\Services\CartsDashboard;

use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\LaboratoryPurchase;
use App\Support\CartsDashboard\CartsDashboardFilter;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class CartsDashboardRepository
{
    /**
     * @return array{
     *     created: int,
     *     abandoned: int,
     *     completed: int,
     *     recovered: int,
     *     abandoned_value: float,
     *     completed_value: float,
     *     recovered_value: float,
     *     revenue: float,
     *     conversion_percent: float|null,
     *     avg_ticket: float|null
     * }
     */
    public function summary(CartsDashboardFilter $filter, Carbon $start, Carbon $end): array
    {
        $staleBefore = now()->subMinutes(Cart::ABANDONED_AFTER_MINUTES);

        $created = (int) $this->baseCartsQuery($filter)
            ->whereBetween('carts.created_at', [$start, $end])
            ->count('carts.id');

        $abandonedQuery = $this->baseCartsQuery($filter)
            ->where('carts.status', MonitoringCartStatus::Active->value)
            ->where('carts.updated_at', '<', $staleBefore)
            ->whereBetween('carts.updated_at', [$start, $end]);

        $abandoned = (int) (clone $abandonedQuery)->count('carts.id');
        $abandonedValue = (float) (clone $abandonedQuery)->sum('carts.total');

        $completedQuery = $this->baseCartsQuery($filter)
            ->where('carts.status', MonitoringCartStatus::Completed->value)
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('carts.completed_at', [$start, $end])
                    ->orWhere(function ($fallback) use ($start, $end) {
                        $fallback->whereNull('carts.completed_at')
                            ->whereBetween('carts.updated_at', [$start, $end]);
                    });
            });

        $completed = (int) (clone $completedQuery)->count('carts.id');
        $completedValue = (float) (clone $completedQuery)->sum('carts.total');

        $recoveredQuery = (clone $completedQuery)
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('users')
                    ->join('customers', 'customers.user_id', '=', 'users.id')
                    ->whereColumn('users.id', 'carts.user_id')
                    ->whereNotNull('customers.cart_abandoned_tagged_at')
                    ->whereColumn(
                        'customers.cart_abandoned_tagged_at',
                        '<',
                        DB::raw('COALESCE(carts.completed_at, carts.updated_at)')
                    );
            });

        $recovered = (int) (clone $recoveredQuery)->count('carts.id');
        $recoveredValue = (float) (clone $recoveredQuery)->sum('carts.total');

        $revenue = $this->revenueAmount($filter, $start, $end);

        $denom = $completed + $abandoned;
        $conversion = $denom > 0 ? round(100 * $completed / $denom, 1) : null;
        $avgTicket = $completed > 0 ? round($completedValue / $completed, 2) : null;

        return [
            'created' => $created,
            'abandoned' => $abandoned,
            'completed' => $completed,
            'recovered' => $recovered,
            'abandoned_value' => round($abandonedValue, 2),
            'completed_value' => round($completedValue, 2),
            'recovered_value' => round($recoveredValue, 2),
            'revenue' => round($revenue, 2),
            'conversion_percent' => $conversion,
            'avg_ticket' => $avgTicket,
        ];
    }

    /**
     * @return list<array{date: string, label: string, sold_amount: float, abandoned_amount: float, sold_count: int, abandoned_count: int}>
     */
    public function dailySalesVsAbandoned(CartsDashboardFilter $filter): array
    {
        $days = $this->localDateKeys($filter->startLocal, $filter->endLocal);
        $sold = $this->dailyCompletedTotals($filter);
        $abandoned = $this->dailyAbandonedTotals($filter);

        return collect($days)->map(function (string $date) use ($sold, $abandoned) {
            $soldRow = $sold[$date] ?? ['amount' => 0.0, 'count' => 0];
            $abandonedRow = $abandoned[$date] ?? ['amount' => 0.0, 'count' => 0];

            return [
                'date' => $date,
                'label' => Carbon::parse($date)->format('d/m'),
                'sold_amount' => round((float) $soldRow['amount'], 2),
                'abandoned_amount' => round((float) $abandonedRow['amount'], 2),
                'sold_count' => (int) $soldRow['count'],
                'abandoned_count' => (int) $abandonedRow['count'],
            ];
        })->values()->all();
    }

    /**
     * @return list<array{date: string, label: string, value: float|int}>
     */
    public function sparkline(CartsDashboardFilter $filter, string $metric): array
    {
        $end = $filter->endLocal->copy();
        $start = $end->copy()->subDays(13)->startOfDay();
        if ($start->lessThan($filter->startLocal)) {
            $start = $filter->startLocal->copy()->startOfDay();
        }

        $window = new CartsDashboardFilter(
            start: $start->copy()->utc(),
            end: $end->copy()->endOfDay()->utc(),
            previousStart: $filter->previousStart,
            previousEnd: $filter->previousEnd,
            startLocal: $start,
            endLocal: $end->copy()->endOfDay(),
            type: $filter->type,
            brand: $filter->brand,
            displayStatus: $filter->displayStatus,
            city: $filter->city,
            paymentMethod: $filter->paymentMethod,
        );

        $series = $this->dailySalesVsAbandoned($window);

        return collect($series)->map(function (array $row) use ($metric) {
            $value = match ($metric) {
                'created', 'abandoned_count' => $row['abandoned_count'],
                'completed', 'sold_count' => $row['sold_count'],
                'abandoned_value' => $row['abandoned_amount'],
                'completed_value', 'revenue' => $row['sold_amount'],
                default => $row['sold_count'],
            };

            return [
                'date' => $row['date'],
                'label' => $row['label'],
                'value' => $value,
            ];
        })->values()->all();
    }

    /**
     * Daily created counts for sparkline of "created".
     *
     * @return array<string, int>
     */
    public function dailyCreatedCounts(CartsDashboardFilter $filter): array
    {
        $driver = DB::connection()->getDriverName();
        $dateExpr = $this->localDateExpression('carts.created_at', $driver);

        $rows = $this->baseCartsQuery($filter)
            ->whereBetween('carts.created_at', [$filter->start, $filter->end])
            ->selectRaw("{$dateExpr} as day_key, COUNT(*) as aggregate")
            ->groupBy('day_key')
            ->pluck('aggregate', 'day_key');

        return $rows->map(fn ($v) => (int) $v)->all();
    }

    /**
     * @return list<array{
     *     brand: string,
     *     brand_label: string,
     *     sales_count: int,
     *     abandoned_count: int,
     *     carts_count: int,
     *     conversion_percent: float|null,
     *     revenue: float,
     *     abandoned_value: float
     * }>
     */
    public function laboratoryRanking(CartsDashboardFilter $filter): array
    {
        if ($filter->type === MonitoringCartType::Pharmacy->value) {
            return [];
        }

        $staleBefore = now()->subMinutes(Cart::ABANDONED_AFTER_MINUTES);
        $brandList = $filter->brand
            ? (LaboratoryBrand::tryFrom($filter->brand) ? [LaboratoryBrand::from($filter->brand)] : [])
            : LaboratoryBrand::cases();

        if ($brandList === []) {
            return [];
        }

        return collect($brandList)->map(function (LaboratoryBrand $brand) use ($filter, $staleBefore) {
            $salesCount = (int) $this->baseCartsQuery($filter, forceBrand: $brand->value)
                ->where('carts.status', MonitoringCartStatus::Completed->value)
                ->where(function ($query) use ($filter) {
                    $query->whereBetween('carts.completed_at', [$filter->start, $filter->end])
                        ->orWhere(function ($fallback) use ($filter) {
                            $fallback->whereNull('carts.completed_at')
                                ->whereBetween('carts.updated_at', [$filter->start, $filter->end]);
                        });
                })
                ->count('carts.id');

            $abandonedQuery = $this->baseCartsQuery($filter, forceBrand: $brand->value)
                ->where('carts.status', MonitoringCartStatus::Active->value)
                ->where('carts.updated_at', '<', $staleBefore)
                ->whereBetween('carts.updated_at', [$filter->start, $filter->end]);

            $abandonedCount = (int) (clone $abandonedQuery)->count('carts.id');
            $abandonedValue = (float) (clone $abandonedQuery)->sum('carts.total');

            $createdCount = (int) $this->baseCartsQuery($filter, forceBrand: $brand->value)
                ->whereBetween('carts.created_at', [$filter->start, $filter->end])
                ->count('carts.id');

            $revenue = $this->labPurchaseRevenue($filter, $brand->value);
            $denom = $salesCount + $abandonedCount;

            return [
                'brand' => $brand->value,
                'brand_label' => $brand->label(),
                'sales_count' => $salesCount,
                'abandoned_count' => $abandonedCount,
                'carts_count' => $createdCount,
                'conversion_percent' => $denom > 0 ? round(100 * $salesCount / $denom, 1) : null,
                'revenue' => round($revenue, 2),
                'abandoned_value' => round($abandonedValue, 2),
            ];
        })
            ->filter(fn (array $row) => $row['sales_count'] > 0
                || $row['abandoned_count'] > 0
                || $row['carts_count'] > 0
                || $row['revenue'] > 0)
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     sold: list<array{id: string, name: string, quantity: int, revenue: float}>,
     *     abandoned: list<array{id: string, name: string, carts: int, value: float}>,
     *     by_revenue: list<array{id: string, name: string, quantity: int, revenue: float}>
     * }
     */
    public function topStudies(CartsDashboardFilter $filter, int $limit = 10): array
    {
        $sold = $this->topSoldStudies($filter, $limit);
        $abandoned = $this->topAbandonedStudies($filter, $limit);

        return [
            'sold' => $sold,
            'abandoned' => $abandoned,
            'by_revenue' => $sold,
        ];
    }

    /**
     * @return array{
     *     buckets: list<array{key: string, label: string, sold_count: int, abandoned_count: int, sold_value: float, abandoned_value: float}>,
     *     avg_ticket_sold: float|null,
     *     avg_ticket_abandoned: float|null
     * }
     */
    public function revenueDistribution(CartsDashboardFilter $filter): array
    {
        $buckets = [
            ['key' => '0_500', 'label' => '$0–$500', 'min' => 0.0, 'max' => 500.0],
            ['key' => '500_1000', 'label' => '$500–$1,000', 'min' => 500.0, 'max' => 1000.0],
            ['key' => '1000_2000', 'label' => '$1,000–$2,000', 'min' => 1000.0, 'max' => 2000.0],
            ['key' => '2000_plus', 'label' => 'Más de $2,000', 'min' => 2000.0, 'max' => null],
        ];

        $soldRows = $this->cartTotalsForStatus($filter, completed: true);
        $abandonedRows = $this->cartTotalsForStatus($filter, completed: false);

        $result = [];
        foreach ($buckets as $bucket) {
            $sold = $this->filterTotalsInBucket($soldRows, $bucket['min'], $bucket['max']);
            $abandoned = $this->filterTotalsInBucket($abandonedRows, $bucket['min'], $bucket['max']);

            $result[] = [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'sold_count' => $sold['count'],
                'abandoned_count' => $abandoned['count'],
                'sold_value' => round($sold['value'], 2),
                'abandoned_value' => round($abandoned['value'], 2),
            ];
        }

        $soldCount = count($soldRows);
        $abandonedCount = count($abandonedRows);
        $soldSum = array_sum($soldRows);
        $abandonedSum = array_sum($abandonedRows);

        return [
            'buckets' => $result,
            'avg_ticket_sold' => $soldCount > 0 ? round($soldSum / $soldCount, 2) : null,
            'avg_ticket_abandoned' => $abandonedCount > 0 ? round($abandonedSum / $abandonedCount, 2) : null,
        ];
    }

    /**
     * @return list<string>
     */
    public function availableCities(): array
    {
        return DB::table('laboratory_purchases')
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->distinct()
            ->orderBy('city')
            ->limit(100)
            ->pluck('city')
            ->map(fn ($city) => (string) $city)
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, name: string, quantity: int, revenue: float}>
     */
    private function topSoldStudies(CartsDashboardFilter $filter, int $limit): array
    {
        if ($filter->type === MonitoringCartType::Pharmacy->value) {
            return [];
        }

        $query = DB::table('laboratory_purchase_items as lpi')
            ->join('laboratory_purchases as lp', 'lp.id', '=', 'lpi.laboratory_purchase_id')
            ->whereNull('lpi.deleted_at')
            ->whereNull('lp.deleted_at')
            ->whereBetween('lp.created_at', [$filter->start, $filter->end])
            ->when($filter->brand, fn ($q) => $q->where('lp.brand', $filter->brand))
            ->when($filter->city, fn ($q) => $q->where('lp.city', 'like', '%'.$filter->city.'%'));

        if ($filter->paymentMethod) {
            $query->whereExists(function (Builder $sub) use ($filter) {
                $sub->select(DB::raw(1))
                    ->from('transactionables')
                    ->join('transactions', 'transactions.id', '=', 'transactionables.transaction_id')
                    ->whereColumn('transactionables.transactionable_id', 'lp.id')
                    ->where('transactionables.transactionable_type', LaboratoryPurchase::class)
                    ->where('transactions.payment_method', $filter->paymentMethod);
            });
        }

        return $query
            ->selectRaw('lpi.gda_id as study_id, lpi.name as study_name, COUNT(*) as quantity, SUM(lpi.price_cents) / 100 as revenue')
            ->groupBy('lpi.gda_id', 'lpi.name')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->study_id,
                'name' => (string) $row->study_name,
                'quantity' => (int) $row->quantity,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, name: string, carts: int, value: float}>
     */
    private function topAbandonedStudies(CartsDashboardFilter $filter, int $limit): array
    {
        $staleBefore = now()->subMinutes(Cart::ABANDONED_AFTER_MINUTES);

        return $this->baseCartsQuery($filter)
            ->join('cart_items', 'cart_items.cart_id', '=', 'carts.id')
            ->where('carts.status', MonitoringCartStatus::Active->value)
            ->where('carts.updated_at', '<', $staleBefore)
            ->whereBetween('carts.updated_at', [$filter->start, $filter->end])
            ->selectRaw('cart_items.product_id as study_id, cart_items.name as study_name, COUNT(DISTINCT carts.id) as carts, SUM(cart_items.price * cart_items.quantity) as value')
            ->groupBy('cart_items.product_id', 'cart_items.name')
            ->orderByDesc('carts')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (string) ($row->study_id ?: $row->study_name),
                'name' => (string) $row->study_name,
                'carts' => (int) $row->carts,
                'value' => round((float) $row->value, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<float>
     */
    private function cartTotalsForStatus(CartsDashboardFilter $filter, bool $completed): array
    {
        $query = $this->baseCartsQuery($filter);

        if ($completed) {
            $query->where('carts.status', MonitoringCartStatus::Completed->value)
                ->where(function ($inner) use ($filter) {
                    $inner->whereBetween('carts.completed_at', [$filter->start, $filter->end])
                        ->orWhere(function ($fallback) use ($filter) {
                            $fallback->whereNull('carts.completed_at')
                                ->whereBetween('carts.updated_at', [$filter->start, $filter->end]);
                        });
                });
        } else {
            $query->where('carts.status', MonitoringCartStatus::Active->value)
                ->where('carts.updated_at', '<', now()->subMinutes(Cart::ABANDONED_AFTER_MINUTES))
                ->whereBetween('carts.updated_at', [$filter->start, $filter->end]);
        }

        return $query->pluck('carts.total')
            ->map(fn ($total) => (float) $total)
            ->values()
            ->all();
    }

    /**
     * @param  list<float>  $totals
     * @return array{count: int, value: float}
     */
    private function filterTotalsInBucket(array $totals, float $min, ?float $max): array
    {
        $matched = array_values(array_filter(
            $totals,
            function (float $total) use ($min, $max) {
                if ($max === null) {
                    return $total >= $min;
                }

                return $total >= $min && $total < $max;
            },
        ));

        return [
            'count' => count($matched),
            'value' => array_sum($matched),
        ];
    }

    private function revenueAmount(CartsDashboardFilter $filter, Carbon $start, Carbon $end): float
    {
        $lab = 0.0;
        $pharmacy = 0.0;

        if ($filter->type !== MonitoringCartType::Pharmacy->value) {
            $lab = $this->labPurchaseRevenue($filter, $filter->brand, $start, $end);
        }

        if ($filter->type !== MonitoringCartType::Lab->value && ! $filter->brand) {
            $pharmacyQuery = DB::table('online_pharmacy_purchases')
                ->whereNull('deleted_at')
                ->whereBetween('created_at', [$start, $end]);

            $pharmacy = (float) $pharmacyQuery->sum('total_cents') / 100;
        }

        return $lab + $pharmacy;
    }

    private function labPurchaseRevenue(
        CartsDashboardFilter $filter,
        ?string $brand = null,
        ?Carbon $start = null,
        ?Carbon $end = null,
    ): float {
        $start ??= $filter->start;
        $end ??= $filter->end;
        $brand ??= $filter->brand;

        $query = DB::table('laboratory_purchases')
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$start, $end])
            ->when($brand, fn ($q) => $q->where('brand', $brand))
            ->when($filter->city, fn ($q) => $q->where('city', 'like', '%'.$filter->city.'%'));

        if ($filter->paymentMethod) {
            $query->whereExists(function (Builder $sub) use ($filter) {
                $sub->select(DB::raw(1))
                    ->from('transactionables')
                    ->join('transactions', 'transactions.id', '=', 'transactionables.transaction_id')
                    ->whereColumn('transactionables.transactionable_id', 'laboratory_purchases.id')
                    ->where('transactionables.transactionable_type', LaboratoryPurchase::class)
                    ->where('transactions.payment_method', $filter->paymentMethod);
            });
        }

        return (float) $query->sum('total_cents') / 100;
    }

    /**
     * @return array<string, array{amount: float, count: int}>
     */
    private function dailyCompletedTotals(CartsDashboardFilter $filter): array
    {
        $driver = DB::connection()->getDriverName();
        $dateExpr = $this->localDateExpression('COALESCE(carts.completed_at, carts.updated_at)', $driver);

        $rows = $this->baseCartsQuery($filter)
            ->where('carts.status', MonitoringCartStatus::Completed->value)
            ->where(function ($query) use ($filter) {
                $query->whereBetween('carts.completed_at', [$filter->start, $filter->end])
                    ->orWhere(function ($fallback) use ($filter) {
                        $fallback->whereNull('carts.completed_at')
                            ->whereBetween('carts.updated_at', [$filter->start, $filter->end]);
                    });
            })
            ->selectRaw("{$dateExpr} as day_key, SUM(carts.total) as amount, COUNT(*) as aggregate")
            ->groupBy('day_key')
            ->get();

        return $rows->mapWithKeys(fn ($row) => [
            (string) $row->day_key => [
                'amount' => (float) $row->amount,
                'count' => (int) $row->aggregate,
            ],
        ])->all();
    }

    /**
     * @return array<string, array{amount: float, count: int}>
     */
    private function dailyAbandonedTotals(CartsDashboardFilter $filter): array
    {
        $driver = DB::connection()->getDriverName();
        $dateExpr = $this->localDateExpression('carts.updated_at', $driver);
        $staleBefore = now()->subMinutes(Cart::ABANDONED_AFTER_MINUTES);

        $rows = $this->baseCartsQuery($filter)
            ->where('carts.status', MonitoringCartStatus::Active->value)
            ->where('carts.updated_at', '<', $staleBefore)
            ->whereBetween('carts.updated_at', [$filter->start, $filter->end])
            ->selectRaw("{$dateExpr} as day_key, SUM(carts.total) as amount, COUNT(*) as aggregate")
            ->groupBy('day_key')
            ->get();

        return $rows->mapWithKeys(fn ($row) => [
            (string) $row->day_key => [
                'amount' => (float) $row->amount,
                'count' => (int) $row->aggregate,
            ],
        ])->all();
    }

    private function baseCartsQuery(CartsDashboardFilter $filter, ?string $forceBrand = null): Builder
    {
        $query = DB::table('carts');

        $type = $filter->type;
        $brand = $forceBrand ?? $filter->brand;

        if ($type) {
            $query->where('carts.type', $type);
        }

        if ($filter->displayStatus === 'completed') {
            $query->where('carts.status', MonitoringCartStatus::Completed->value);
        } elseif ($filter->displayStatus === 'abandoned') {
            $query->where('carts.status', MonitoringCartStatus::Active->value)
                ->where('carts.updated_at', '<', now()->subMinutes(Cart::ABANDONED_AFTER_MINUTES));
        } elseif ($filter->displayStatus === 'active') {
            $query->where('carts.status', MonitoringCartStatus::Active->value)
                ->where('carts.updated_at', '>=', now()->subMinutes(Cart::ABANDONED_AFTER_MINUTES));
        }

        if ($brand) {
            $query->where('carts.type', MonitoringCartType::Lab->value)
                ->whereExists(function (Builder $sub) use ($brand) {
                    $sub->select(DB::raw(1))
                        ->from('cart_items')
                        ->join('laboratory_tests', function ($join) {
                            $join->whereRaw('laboratory_tests.id = cart_items.product_id');
                        })
                        ->whereColumn('cart_items.cart_id', 'carts.id')
                        ->where('laboratory_tests.brand', $brand);
                });
        }

        if ($filter->city) {
            $query->whereExists(function (Builder $sub) use ($filter) {
                $sub->select(DB::raw(1))
                    ->from('users')
                    ->join('customers', 'customers.user_id', '=', 'users.id')
                    ->join('laboratory_purchases', 'laboratory_purchases.customer_id', '=', 'customers.id')
                    ->whereColumn('users.id', 'carts.user_id')
                    ->whereNull('laboratory_purchases.deleted_at')
                    ->where('laboratory_purchases.city', 'like', '%'.$filter->city.'%');
            });
        }

        if ($filter->paymentMethod) {
            $query->whereExists(function (Builder $sub) use ($filter) {
                $sub->select(DB::raw(1))
                    ->from('users')
                    ->join('customers', 'customers.user_id', '=', 'users.id')
                    ->join('laboratory_purchases', 'laboratory_purchases.customer_id', '=', 'customers.id')
                    ->join('transactionables', function ($join) {
                        $join->on('transactionables.transactionable_id', '=', 'laboratory_purchases.id')
                            ->where('transactionables.transactionable_type', LaboratoryPurchase::class);
                    })
                    ->join('transactions', 'transactions.id', '=', 'transactionables.transaction_id')
                    ->whereColumn('users.id', 'carts.user_id')
                    ->whereNull('laboratory_purchases.deleted_at')
                    ->where('transactions.payment_method', $filter->paymentMethod);
            });
        }

        return $query;
    }

    private function localDateExpression(string $column, string $driver): string
    {
        return match ($driver) {
            'sqlite' => "strftime('%Y-%m-%d', datetime({$column}, '-6 hours'))",
            default => "DATE(CONVERT_TZ({$column}, '+00:00', '-06:00'))",
        };
    }

    /**
     * @return list<string>
     */
    private function localDateKeys(Carbon $startLocal, Carbon $endLocal): array
    {
        return collect(CarbonPeriod::create($startLocal->copy()->startOfDay(), $endLocal->copy()->startOfDay()))
            ->map(fn (Carbon $day) => $day->toDateString())
            ->values()
            ->all();
    }
}
