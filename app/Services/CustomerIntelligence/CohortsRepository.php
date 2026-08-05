<?php

namespace App\Services\CustomerIntelligence;

use App\Data\StatesMexico;
use App\DTOs\CustomerIntelligence\CohortRetentionRowData;
use App\Models\Customer;
use App\Models\FamilyAccount;
use App\Models\OdessaAfiliateAccount;
use App\Models\RegularAccount;
use App\Support\CustomerIntelligence\CohortsFilter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CohortsRepository
{
    public function customersQuery(CohortsFilter $filter, ?Carbon $from = null, ?Carbon $to = null): Builder
    {
        $from ??= $filter->start;
        $to ??= $filter->end;

        return Customer::query()
            ->whereBetween('customers.created_at', [$from, $to])
            ->when($filter->accountType, function (Builder $q, string $type) {
                $map = [
                    'regular' => RegularAccount::class,
                    'odessa' => OdessaAfiliateAccount::class,
                    'familiar' => FamilyAccount::class,
                ];
                if (isset($map[$type])) {
                    $q->where('customerable_type', $map[$type]);
                }
            })
            ->when($filter->source, function (Builder $q, string $source) {
                match ($source) {
                    'referred' => $q->whereHas('user', fn (Builder $u) => $u->whereNotNull('referred_by')),
                    'odessa' => $q->where('customerable_type', OdessaAfiliateAccount::class),
                    'familiar' => $q->where('customerable_type', FamilyAccount::class),
                    'organico' => $q->where('customerable_type', RegularAccount::class)
                        ->whereHas('user', fn (Builder $u) => $u->whereNull('referred_by')),
                    default => null,
                };
            })
            ->when($filter->state, function (Builder $q, string $state) {
                $q->whereHas('user', fn (Builder $u) => $u->where('state', $state));
            })
            ->when($filter->city, function (Builder $q, string $city) {
                $q->whereHas('addresses', fn (Builder $a) => $a->where('city', $city));
            })
            ->when($filter->gender, function (Builder $q, string $gender) {
                $value = match ($gender) {
                    'male', '1' => 1,
                    'female', '2' => 2,
                    default => null,
                };
                if ($value !== null) {
                    $q->whereHas('user', fn (Builder $u) => $u->where('gender', $value));
                }
            });
    }

    /**
     * Union de todas las compras (lab, farmacia, membresía).
     */
    public function purchasesUnionQuery()
    {
        $lab = DB::table('laboratory_purchases')
            ->select('customer_id', 'created_at', 'total_cents', DB::raw("'lab' as channel"));
        $pharmacy = DB::table('online_pharmacy_purchases')
            ->select('customer_id', 'created_at', 'total_cents', DB::raw("'pharmacy' as channel"));
        $membership = DB::table('medical_attention_subscriptions')
            ->select('customer_id', 'created_at', DB::raw('price_cents as total_cents'), DB::raw("'membership' as channel"));

        if (Schema::hasColumn('laboratory_purchases', 'deleted_at')) {
            $lab->whereNull('deleted_at');
        }
        if (Schema::hasColumn('online_pharmacy_purchases', 'deleted_at')) {
            $pharmacy->whereNull('deleted_at');
        }
        if (Schema::hasColumn('medical_attention_subscriptions', 'deleted_at')) {
            $membership->whereNull('deleted_at');
        }

        return $lab->unionAll($pharmacy)->unionAll($membership);
    }

    /**
     * @return array{
     *   new_customers: int,
     *   recurrent: int,
     *   retained: int,
     *   lost: int,
     *   retention_30: float|null,
     *   retention_60: float|null,
     *   retention_90: float|null,
     *   avg_ltv: float,
     *   avg_days_between: float|null,
     *   repeat_rate: float|null
     * }
     */
    public function summaryKpis(CohortsFilter $filter, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $from ??= $filter->start;
        $to ??= $filter->end;

        $newCustomers = (int) $this->customersQuery($filter, $from, $to)->count();

        $cohortIds = $this->customersQuery($filter, $from, $to)->select('customers.id');

        $purchaseCounts = DB::query()
            ->fromSub($this->purchasesUnionQuery(), 'p')
            ->whereIn('customer_id', $cohortIds)
            ->select('customer_id', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(total_cents) as revenue'), DB::raw('MIN(created_at) as first_at'), DB::raw('MAX(created_at) as last_at'))
            ->groupBy('customer_id');

        $agg = DB::query()
            ->fromSub($purchaseCounts, 'pc')
            ->selectRaw('COUNT(*) as buyers')
            ->selectRaw('SUM(CASE WHEN cnt >= 2 THEN 1 ELSE 0 END) as recurrent')
            ->selectRaw('AVG(revenue) as avg_revenue')
            ->selectRaw('AVG(CASE WHEN cnt >= 2 THEN TIMESTAMPDIFF(DAY, first_at, last_at) / (cnt - 1) END) as avg_gap')
            ->first();

        $buyers = (int) ($agg->buyers ?? 0);
        $recurrent = (int) ($agg->recurrent ?? 0);
        $avgLtv = ((float) ($agg->avg_revenue ?? 0)) / 100;
        $avgGap = $agg->avg_gap !== null ? round((float) $agg->avg_gap, 1) : null;
        $repeatRate = $buyers > 0 ? round(($recurrent / $buyers) * 100, 1) : null;

        $retention30 = $this->nthDayRetention($filter, 30, $from, $to);
        $retention60 = $this->nthDayRetention($filter, 60, $from, $to);
        $retention90 = $this->nthDayRetention($filter, 90, $from, $to);

        // Retenidos: compraron en el periodo y ya tenían compra previa.
        $retained = (int) DB::query()
            ->fromSub(
                DB::query()
                    ->fromSub($this->purchasesUnionQuery(), 'p')
                    ->whereBetween('created_at', [$from, $to])
                    ->whereIn('customer_id', function ($q) use ($from) {
                        $q->select('customer_id')
                            ->fromSub($this->purchasesUnionQuery(), 'prev')
                            ->where('created_at', '<', $from)
                            ->groupBy('customer_id');
                    })
                    ->select('customer_id')
                    ->distinct(),
                'retained_customers'
            )
            ->count();

        $lost = (int) ($this->churnBuckets($filter)['30'] ?? 0);

        return [
            'new_customers' => $newCustomers,
            'recurrent' => $recurrent,
            'retained' => $retained,
            'lost' => $lost,
            'retention_30' => $retention30,
            'retention_60' => $retention60,
            'retention_90' => $retention90,
            'avg_ltv' => round($avgLtv, 2),
            'avg_days_between' => $avgGap,
            'repeat_rate' => $repeatRate,
        ];
    }

    /**
     * % de compradores (1ª compra hace ≥ N días) que repitieron dentro de N días.
     */
    public function nthDayRetention(CohortsFilter $filter, int $days, ?Carbon $from = null, ?Carbon $to = null): ?float
    {
        $from ??= $filter->start;
        $to ??= $filter->end;
        $cohortIds = $this->customersQuery($filter, $from, $to)->select('customers.id');

        $firstPurchases = DB::query()
            ->fromSub($this->purchasesUnionQuery(), 'p')
            ->whereIn('customer_id', $cohortIds)
            ->select('customer_id', DB::raw('MIN(created_at) as first_at'))
            ->groupBy('customer_id')
            ->havingRaw('MIN(created_at) <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)', [$days]);

        $eligible = DB::query()->fromSub($firstPurchases, 'fp')->count();
        if ($eligible === 0) {
            return null;
        }

        $retained = DB::query()
            ->fromSub($firstPurchases, 'fp')
            ->whereExists(function ($q) use ($days) {
                $q->select(DB::raw(1))
                    ->fromSub($this->purchasesUnionQuery(), 'p2')
                    ->whereColumn('p2.customer_id', 'fp.customer_id')
                    ->whereColumn('p2.created_at', '>', 'fp.first_at')
                    ->whereRaw('p2.created_at <= DATE_ADD(fp.first_at, INTERVAL ? DAY)', [$days]);
            })
            ->count();

        return round(($retained / $eligible) * 100, 1);
    }

    /**
     * Matriz de retención por cohort mensual × semana.
     *
     * @return list<CohortRetentionRowData>
     */
    public function cohortHeatmap(CohortsFilter $filter): array
    {
        $cohortMonths = $this->customersQuery($filter)
            ->selectRaw("DATE_FORMAT(CONVERT_TZ(customers.created_at, '+00:00', '-06:00'), '%Y-%m') as cohort_key")
            ->selectRaw('COUNT(*) as size')
            ->groupBy('cohort_key')
            ->orderByDesc('cohort_key')
            ->limit($filter->maxCohorts)
            ->pluck('size', 'cohort_key');

        if ($cohortMonths->isEmpty()) {
            return [];
        }

        $keys = $cohortMonths->keys()->all();

        // Clientes del cohort con semana relativa de cada compra.
        $activity = DB::table('customers')
            ->joinSub($this->purchasesUnionQuery(), 'p', 'p.customer_id', '=', 'customers.id')
            ->whereNull('customers.deleted_at')
            ->whereBetween('customers.created_at', [$filter->start, $filter->end])
            ->whereIn(DB::raw("DATE_FORMAT(CONVERT_TZ(customers.created_at, '+00:00', '-06:00'), '%Y-%m')"), $keys)
            ->selectRaw("DATE_FORMAT(CONVERT_TZ(customers.created_at, '+00:00', '-06:00'), '%Y-%m') as cohort_key")
            ->selectRaw('customers.id as customer_id')
            ->selectRaw('FLOOR(TIMESTAMPDIFF(DAY, customers.created_at, p.created_at) / 7) as week_n')
            ->whereRaw('p.created_at >= customers.created_at')
            ->whereRaw('FLOOR(TIMESTAMPDIFF(DAY, customers.created_at, p.created_at) / 7) BETWEEN 0 AND ?', [$filter->maxWeeks - 1])
            ->distinct()
            ->get();

        $retainedMap = [];
        foreach ($activity as $row) {
            $retainedMap[$row->cohort_key][(int) $row->week_n][$row->customer_id] = true;
        }

        $rows = [];
        foreach ($cohortMonths->sortKeys() as $cohortKey => $size) {
            $weeks = [];
            for ($w = 0; $w < $filter->maxWeeks; $w++) {
                $retained = isset($retainedMap[$cohortKey][$w])
                    ? count($retainedMap[$cohortKey][$w])
                    : 0;
                $percent = $size > 0 ? round(($retained / $size) * 100, 1) : null;
                // Week 0: tratados como "activos al registro" = al menos engagement/compra en semana 0,
                // o 100% del cohort como baseline de tamaño (Mixpanel-style: W0 = 100%).
                if ($w === 0) {
                    $percent = 100.0;
                    $retained = (int) $size;
                }
                $weeks[] = [
                    'week' => $w,
                    'retained' => $retained,
                    'percent' => $percent,
                ];
            }

            $rows[] = new CohortRetentionRowData(
                cohortKey: (string) $cohortKey,
                cohortLabel: $this->formatCohortLabel((string) $cohortKey),
                size: (int) $size,
                weeks: $weeks,
            );
        }

        return $rows;
    }

    /**
     * Curvas de retención (una serie por cohort + promedio).
     *
     * @param  list<CohortRetentionRowData>  $heatmap
     * @return array{series: list<array{id: string, label: string, points: list<array{week: int, percent: float|null}>}>, average: list<array{week: int, percent: float|null}>}
     */
    public function retentionCurves(array $heatmap): array
    {
        $series = [];
        $sums = [];
        $counts = [];

        foreach ($heatmap as $row) {
            $points = [];
            foreach ($row->weeks as $week) {
                $points[] = [
                    'week' => $week['week'],
                    'percent' => $week['percent'],
                ];
                if ($week['percent'] !== null) {
                    $sums[$week['week']] = ($sums[$week['week']] ?? 0) + $week['percent'];
                    $counts[$week['week']] = ($counts[$week['week']] ?? 0) + 1;
                }
            }
            $series[] = [
                'id' => $row->cohortKey,
                'label' => $row->cohortLabel,
                'points' => $points,
            ];
        }

        $average = [];
        $maxWeek = collect($heatmap)->max(fn (CohortRetentionRowData $r) => count($r->weeks)) ?: 0;
        for ($w = 0; $w < $maxWeek; $w++) {
            $average[] = [
                'week' => $w,
                'percent' => isset($counts[$w]) && $counts[$w] > 0
                    ? round($sums[$w] / $counts[$w], 1)
                    : null,
            ];
        }

        return [
            'series' => $series,
            'average' => $average,
        ];
    }

    /**
     * Retención por fuente (proxy: orgánico / referidos / odessa / familiar).
     *
     * @return list<array{key: string, label: string, customers: int, buyers: int, recurrent: int, retention_30: float|null, repeat_rate: float|null, avg_ltv: float}>
     */
    public function retentionBySource(CohortsFilter $filter): array
    {
        $sources = [
            'organico' => 'Orgánico',
            'referred' => 'Referidos',
            'odessa' => 'Odessa',
            'familiar' => 'Familiar',
        ];

        $rows = [];
        foreach ($sources as $key => $label) {
            $sourceFilter = new CohortsFilter(
                start: $filter->start,
                end: $filter->end,
                previousStart: $filter->previousStart,
                previousEnd: $filter->previousEnd,
                startLocal: $filter->startLocal,
                endLocal: $filter->endLocal,
                accountType: $filter->accountType,
                source: $key,
                state: $filter->state,
                city: $filter->city,
                gender: $filter->gender,
                maxWeeks: $filter->maxWeeks,
                maxCohorts: $filter->maxCohorts,
                tab: $filter->tab,
            );

            $kpis = $this->summaryKpis($sourceFilter);
            if ($kpis['new_customers'] === 0) {
                continue;
            }

            $rows[] = [
                'key' => $key,
                'label' => $label,
                'customers' => $kpis['new_customers'],
                'buyers' => null,
                'recurrent' => $kpis['recurrent'],
                'retention_30' => $kpis['retention_30'],
                'repeat_rate' => $kpis['repeat_rate'],
                'avg_ltv' => $kpis['avg_ltv'],
            ];
        }

        return collect($rows)->sortByDesc('retention_30')->values()->all();
    }

    /**
     * @return list<array{key: string, label: string, count: int, percent: float}>
     */
    public function repeatPurchaseLadder(CohortsFilter $filter): array
    {
        $cohortIds = $this->customersQuery($filter)->select('customers.id');

        $counts = DB::query()
            ->fromSub($this->purchasesUnionQuery(), 'p')
            ->whereIn('customer_id', $cohortIds)
            ->select('customer_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('customer_id');

        $agg = DB::query()
            ->fromSub($counts, 'c')
            ->selectRaw('SUM(CASE WHEN cnt >= 1 THEN 1 ELSE 0 END) as gte1')
            ->selectRaw('SUM(CASE WHEN cnt >= 2 THEN 1 ELSE 0 END) as gte2')
            ->selectRaw('SUM(CASE WHEN cnt >= 3 THEN 1 ELSE 0 END) as gte3')
            ->selectRaw('SUM(CASE WHEN cnt >= 4 THEN 1 ELSE 0 END) as gte4')
            ->selectRaw('SUM(CASE WHEN cnt >= 5 THEN 1 ELSE 0 END) as gte5')
            ->first();

        $base = max(1, (int) ($agg->gte1 ?? 0));
        $steps = [
            ['key' => 'first', 'label' => 'Primera compra', 'count' => (int) ($agg->gte1 ?? 0)],
            ['key' => 'second', 'label' => 'Segunda compra', 'count' => (int) ($agg->gte2 ?? 0)],
            ['key' => 'third', 'label' => 'Tercera compra', 'count' => (int) ($agg->gte3 ?? 0)],
            ['key' => 'fourth', 'label' => 'Cuarta compra', 'count' => (int) ($agg->gte4 ?? 0)],
            ['key' => 'frequent', 'label' => 'Cliente frecuente (5+)', 'count' => (int) ($agg->gte5 ?? 0)],
        ];

        return collect($steps)->map(fn (array $step) => [
            ...$step,
            'percent' => round(($step['count'] / $base) * 100, 1),
        ])->all();
    }

    /**
     * @return list<array{key: string, label: string, count: int, percent: float}>
     */
    public function daysBetweenPurchases(CohortsFilter $filter): array
    {
        $cohortIds = $this->customersQuery($filter)->select('customers.id');

        // Gaps entre compras consecutivas por cliente (muestra vía SQL window).
        $ordered = DB::query()
            ->fromSub($this->purchasesUnionQuery(), 'p')
            ->whereIn('customer_id', $cohortIds)
            ->select('customer_id', 'created_at')
            ->selectRaw('LAG(created_at) OVER (PARTITION BY customer_id ORDER BY created_at) as prev_at');

        $gaps = DB::query()
            ->fromSub($ordered, 'o')
            ->whereNotNull('prev_at')
            ->selectRaw('TIMESTAMPDIFF(DAY, prev_at, created_at) as gap_days')
            ->get();

        $buckets = [
            '0-7' => 0,
            '8-15' => 0,
            '16-30' => 0,
            '31-60' => 0,
            '61-90' => 0,
            '90+' => 0,
        ];

        foreach ($gaps as $row) {
            $d = (int) $row->gap_days;
            if ($d <= 7) {
                $buckets['0-7']++;
            } elseif ($d <= 15) {
                $buckets['8-15']++;
            } elseif ($d <= 30) {
                $buckets['16-30']++;
            } elseif ($d <= 60) {
                $buckets['31-60']++;
            } elseif ($d <= 90) {
                $buckets['61-90']++;
            } else {
                $buckets['90+']++;
            }
        }

        $total = max(1, array_sum($buckets));
        $labels = [
            '0-7' => '0–7 días',
            '8-15' => '8–15 días',
            '16-30' => '16–30 días',
            '31-60' => '31–60 días',
            '61-90' => '61–90 días',
            '90+' => '90+ días',
        ];

        return collect($buckets)->map(fn (int $count, string $key) => [
            'key' => $key,
            'label' => $labels[$key],
            'count' => $count,
            'percent' => round(($count / $total) * 100, 1),
        ])->values()->all();
    }

    /**
     * @return array<string, int>
     */
    public function churnBuckets(CohortsFilter $filter): array
    {
        $now = now();
        $thresholds = [30, 60, 90, 180, 365];
        $result = [];

        $lastPurchase = DB::query()
            ->fromSub($this->purchasesUnionQuery(), 'p')
            ->select('customer_id', DB::raw('MAX(created_at) as last_at'))
            ->groupBy('customer_id');

        // Restringir a clientes del universo filtrado (todos los que matchean filtros sin date forzoso en created — churn es global filtrado).
        $universe = Customer::query()
            ->when($filter->accountType, function (Builder $q, string $type) {
                $map = [
                    'regular' => RegularAccount::class,
                    'odessa' => OdessaAfiliateAccount::class,
                    'familiar' => FamilyAccount::class,
                ];
                if (isset($map[$type])) {
                    $q->where('customerable_type', $map[$type]);
                }
            })
            ->when($filter->source, function (Builder $q, string $source) {
                match ($source) {
                    'referred' => $q->whereHas('user', fn (Builder $u) => $u->whereNotNull('referred_by')),
                    'odessa' => $q->where('customerable_type', OdessaAfiliateAccount::class),
                    'familiar' => $q->where('customerable_type', FamilyAccount::class),
                    'organico' => $q->where('customerable_type', RegularAccount::class)
                        ->whereHas('user', fn (Builder $u) => $u->whereNull('referred_by')),
                    default => null,
                };
            })
            ->when($filter->state, fn (Builder $q, string $state) => $q->whereHas('user', fn (Builder $u) => $u->where('state', $state)))
            ->when($filter->city, fn (Builder $q, string $city) => $q->whereHas('addresses', fn (Builder $a) => $a->where('city', $city)))
            ->when($filter->gender, function (Builder $q, string $gender) {
                $value = match ($gender) {
                    'male', '1' => 1,
                    'female', '2' => 2,
                    default => null,
                };
                if ($value !== null) {
                    $q->whereHas('user', fn (Builder $u) => $u->where('gender', $value));
                }
            })
            ->select('customers.id');

        foreach ($thresholds as $days) {
            $result[(string) $days] = (int) DB::query()
                ->fromSub($lastPurchase, 'lp')
                ->whereIn('customer_id', $universe)
                ->where('last_at', '<', $now->copy()->subDays($days))
                ->count();
        }

        return $result;
    }

    /**
     * @return list<array{dimension: string, key: string, label: string, customers: int, avg_ltv: float, total_ltv: float}>
     */
    public function ltvBreakdown(CohortsFilter $filter): array
    {
        $cohortIds = $this->customersQuery($filter)->select('customers.id');

        $revenueByCustomer = DB::query()
            ->fromSub($this->purchasesUnionQuery(), 'p')
            ->whereIn('customer_id', $cohortIds)
            ->select('customer_id', DB::raw('SUM(total_cents) as revenue'))
            ->groupBy('customer_id');

        $byState = DB::table('customers')
            ->join('users', 'users.id', '=', 'customers.user_id')
            ->joinSub($revenueByCustomer, 'r', 'r.customer_id', '=', 'customers.id')
            ->whereBetween('customers.created_at', [$filter->start, $filter->end])
            ->whereNotNull('users.state')
            ->where('users.state', '!=', '')
            ->selectRaw('users.state as dim_key, COUNT(*) as customers, AVG(r.revenue) as avg_revenue, SUM(r.revenue) as total_revenue')
            ->groupBy('users.state')
            ->orderByDesc('avg_revenue')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'dimension' => 'state',
                'key' => $row->dim_key,
                'label' => StatesMexico::obtenerNombre($row->dim_key) ?? $row->dim_key,
                'customers' => (int) $row->customers,
                'avg_ltv' => round(((float) $row->avg_revenue) / 100, 2),
                'total_ltv' => round(((float) $row->total_revenue) / 100, 2),
            ])
            ->all();

        $byCity = DB::table('customers')
            ->join('addresses', 'addresses.customer_id', '=', 'customers.id')
            ->joinSub($revenueByCustomer, 'r', 'r.customer_id', '=', 'customers.id')
            ->whereBetween('customers.created_at', [$filter->start, $filter->end])
            ->whereNotNull('addresses.city')
            ->where('addresses.city', '!=', '')
            ->selectRaw('addresses.city as dim_key, COUNT(DISTINCT customers.id) as customers, AVG(r.revenue) as avg_revenue, SUM(r.revenue) as total_revenue')
            ->groupBy('addresses.city')
            ->orderByDesc('avg_revenue')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'dimension' => 'city',
                'key' => $row->dim_key,
                'label' => $row->dim_key,
                'customers' => (int) $row->customers,
                'avg_ltv' => round(((float) $row->avg_revenue) / 100, 2),
                'total_ltv' => round(((float) $row->total_revenue) / 100, 2),
            ])
            ->all();

        $byGender = DB::table('customers')
            ->join('users', 'users.id', '=', 'customers.user_id')
            ->joinSub($revenueByCustomer, 'r', 'r.customer_id', '=', 'customers.id')
            ->whereBetween('customers.created_at', [$filter->start, $filter->end])
            ->whereNotNull('users.gender')
            ->selectRaw('users.gender as dim_key, COUNT(*) as customers, AVG(r.revenue) as avg_revenue, SUM(r.revenue) as total_revenue')
            ->groupBy('users.gender')
            ->orderByDesc('avg_revenue')
            ->get()
            ->map(fn ($row) => [
                'dimension' => 'gender',
                'key' => (string) $row->dim_key,
                'label' => match ((int) $row->dim_key) {
                    1 => 'Masculino',
                    2 => 'Femenino',
                    default => (string) $row->dim_key,
                },
                'customers' => (int) $row->customers,
                'avg_ltv' => round(((float) $row->avg_revenue) / 100, 2),
                'total_ltv' => round(((float) $row->total_revenue) / 100, 2),
            ])
            ->all();

        $bySource = collect($this->retentionBySource($filter))->map(fn (array $row) => [
            'dimension' => 'source',
            'key' => $row['key'],
            'label' => $row['label'],
            'customers' => $row['customers'],
            'avg_ltv' => $row['avg_ltv'],
            'total_ltv' => round($row['avg_ltv'] * max(1, $row['recurrent']), 2),
        ])->all();

        // LTV por canal de compra (laboratorio / farmacia / membresía).
        $byChannel = DB::query()
            ->fromSub($this->purchasesUnionQuery(), 'p')
            ->whereIn('customer_id', $cohortIds)
            ->selectRaw('channel as dim_key, COUNT(DISTINCT customer_id) as customers, AVG(total_cents) as avg_ticket, SUM(total_cents) as total_revenue')
            ->groupBy('channel')
            ->get()
            ->map(fn ($row) => [
                'dimension' => 'channel',
                'key' => $row->dim_key,
                'label' => match ($row->dim_key) {
                    'lab' => 'Laboratorio',
                    'pharmacy' => 'Farmacia',
                    'membership' => 'Membresía',
                    default => (string) $row->dim_key,
                },
                'customers' => (int) $row->customers,
                'avg_ltv' => round(((float) $row->total_revenue / max(1, (int) $row->customers)) / 100, 2),
                'total_ltv' => round(((float) $row->total_revenue) / 100, 2),
            ])
            ->all();

        return array_merge($bySource, $byState, $byCity, $byGender, $byChannel);
    }

    /**
     * @return list<array{id: string, label: string, count: int, description: string}>
     */
    public function segmentationCards(CohortsFilter $filter): array
    {
        $base = $this->customersQuery($filter);
        $now = now();

        return [
            [
                'id' => 'new',
                'label' => 'Clientes nuevos del periodo',
                'count' => (int) (clone $base)->count(),
                'description' => 'Registrados en el rango filtrado',
            ],
            [
                'id' => 'with_purchase',
                'label' => 'Con al menos 1 compra',
                'count' => (int) (clone $base)->where(function (Builder $q) {
                    $q->whereHas('laboratoryPurchases')
                        ->orWhereHas('onlinePharmacyPurchases')
                        ->orWhereHas('medicalAttentionSubscriptions');
                })->count(),
                'description' => 'Activados comercialmente',
            ],
            [
                'id' => 'recurrent',
                'label' => 'Recurrentes (2+)',
                'count' => $this->summaryKpis($filter)['recurrent'],
                'description' => 'Repitieron compra',
            ],
            [
                'id' => 'churn_90',
                'label' => 'Churn 90 días',
                'count' => $this->churnBuckets($filter)['90'] ?? 0,
                'description' => 'Sin compra en 90+ días',
            ],
            [
                'id' => 'membership',
                'label' => 'Con membresía',
                'count' => (int) (clone $base)->whereHas('medicalAttentionSubscriptions')->count(),
                'description' => 'Suscriptores en el cohort',
            ],
            [
                'id' => 'stale',
                'label' => 'Sin actividad 30d',
                'count' => (int) (clone $base)->where('customers.updated_at', '<', $now->copy()->subDays(30))->count(),
                'description' => 'Proxy de engagement frío',
            ],
        ];
    }

    /**
     * @return Collection<int, string>
     */
    public function availableCities(): Collection
    {
        return DB::table('addresses')
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->distinct()
            ->orderBy('city')
            ->limit(200)
            ->pluck('city');
    }

    private function formatCohortLabel(string $key): string
    {
        try {
            return Carbon::createFromFormat('Y-m', $key, 'America/Monterrey')
                ->locale('es')
                ->isoFormat('MMM YYYY');
        } catch (\Throwable) {
            return $key;
        }
    }
}
