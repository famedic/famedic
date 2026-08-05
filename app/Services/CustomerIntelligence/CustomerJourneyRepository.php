<?php

namespace App\Services\CustomerIntelligence;

use App\DTOs\CustomerIntelligence\JourneyPathData;
use App\DTOs\CustomerIntelligence\JourneyStageData;
use App\Models\Customer;
use App\Models\FamilyAccount;
use App\Models\OdessaAfiliateAccount;
use App\Models\RegularAccount;
use App\Support\CustomerIntelligence\CustomerJourneyFilter;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CustomerJourneyRepository
{
    /**
     * @return list<array{key: string, label: string}>
     */
    public function stageDefinitions(): array
    {
        return [
            ['key' => 'registration', 'label' => 'Registro'],
            ['key' => 'email_verified', 'label' => 'Verificó Email'],
            ['key' => 'first_login', 'label' => 'Primer Login'],
            ['key' => 'visited_lab', 'label' => 'Visitó Laboratorios'],
            ['key' => 'visited_pharmacy', 'label' => 'Visitó Farmacia'],
            ['key' => 'visited_membership', 'label' => 'Visitó Membresías'],
            ['key' => 'searched_products', 'label' => 'Buscó Productos'],
            ['key' => 'added_cart', 'label' => 'Agregó al carrito'],
            ['key' => 'started_checkout', 'label' => 'Inició Checkout'],
            ['key' => 'payment', 'label' => 'Pago'],
            ['key' => 'first_purchase', 'label' => 'Primera Compra'],
            ['key' => 'second_purchase', 'label' => 'Segunda Compra'],
            ['key' => 'frequent', 'label' => 'Cliente Frecuente'],
        ];
    }

    public function cohortQuery(CustomerJourneyFilter $filter, ?Carbon $from = null, ?Carbon $to = null): Builder
    {
        $from ??= $filter->start;
        $to ??= $filter->end;

        return Customer::query()
            ->whereBetween('customers.created_at', [$from, $to])
            ->when($filter->search, function (Builder $q, string $search) {
                $q->whereHas('user', function (Builder $user) use ($search) {
                    $user->where(function (Builder $u) use ($search) {
                        $u->where('name', 'like', "%{$search}%")
                            ->orWhere('paternal_lastname', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                });
            })
            ->when($filter->accountType, function (Builder $q, string $type) {
                $map = [
                    'regular' => RegularAccount::class,
                    'odessa' => OdessaAfiliateAccount::class,
                    'familiar' => FamilyAccount::class,
                ];
                if (isset($map[$type])) {
                    $q->where('customerable_type', $map[$type]);
                }
            });
    }

    /**
     * Conteos por etapa del journey (cohort de registros del periodo).
     *
     * @return array<string, int>
     */
    public function stageCounts(CustomerJourneyFilter $filter, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $base = $this->cohortQuery($filter, $from, $to);
        $registered = (int) (clone $base)->count();

        $emailVerified = (int) (clone $base)->whereHas('user', fn (Builder $u) => $u->whereNotNull('email_verified_at'))->count();

        // Proxy de primer login: email o teléfono verificado (engagement post-registro).
        $firstLogin = (int) (clone $base)->whereHas('user', function (Builder $u) {
            $u->where(function (Builder $inner) {
                $inner->whereNotNull('email_verified_at')
                    ->orWhereNotNull('phone_verified_at');
            });
        })->count();

        $visitedLab = (int) (clone $base)->where(function (Builder $q) {
            $q->whereHas('laboratoryCartItems')
                ->orWhereHas('laboratoryAppointments')
                ->orWhereHas('laboratoryPurchases');
        })->count();

        $visitedPharmacy = (int) (clone $base)->where(function (Builder $q) {
            $q->whereHas('onlinePharmacyCartItems')
                ->orWhereHas('onlinePharmacyPurchases');
        })->count();

        $visitedMembership = (int) (clone $base)->where(function (Builder $q) {
            $q->whereHas('medicalAttentionSubscriptions')
                ->orWhereNotNull('medical_attention_identifier');
        })->count();

        $addedCart = (int) (clone $base)->where(function (Builder $q) {
            $q->whereHas('laboratoryCartItems')
                ->orWhereHas('onlinePharmacyCartItems');
        })->count();

        // Proxy búsqueda: mismo universo de carrito / compras (sin event stream de search).
        $searchedProducts = max($addedCart, (int) (clone $base)->where(function (Builder $q) {
            $q->whereHas('laboratoryCartItems')
                ->orWhereHas('onlinePharmacyCartItems')
                ->orWhereHas('laboratoryPurchases')
                ->orWhereHas('onlinePharmacyPurchases');
        })->count());

        $startedCheckout = (int) (clone $base)->whereHas('laboratoryCheckoutDrafts')->count();

        $purchased = (int) (clone $base)->where(function (Builder $q) {
            $q->whereHas('laboratoryPurchases')
                ->orWhereHas('onlinePharmacyPurchases')
                ->orWhereHas('medicalAttentionSubscriptions');
        })->count();

        $purchaseCounts = $this->purchaseCountDistribution($filter, $from, $to);
        $secondPurchase = (int) ($purchaseCounts['gte_2'] ?? 0);
        $frequent = (int) ($purchaseCounts['gte_3'] ?? 0);

        // Pago ≈ quienes llegaron a compra (no hay eventos de intento de pago fallidos).
        $payment = $purchased;

        return [
            'registration' => $registered,
            'email_verified' => $emailVerified,
            'first_login' => $firstLogin,
            'visited_lab' => $visitedLab,
            'visited_pharmacy' => $visitedPharmacy,
            'visited_membership' => $visitedMembership,
            'searched_products' => $searchedProducts,
            'added_cart' => $addedCart,
            'started_checkout' => max($startedCheckout, $purchased),
            'payment' => $payment,
            'first_purchase' => $purchased,
            'second_purchase' => $secondPurchase,
            'frequent' => $frequent,
        ];
    }

    /**
     * @return array{gte_2: int, gte_3: int}
     */
    private function purchaseCountDistribution(CustomerJourneyFilter $filter, ?Carbon $from, ?Carbon $to): array
    {
        $from ??= $filter->start;
        $to ??= $filter->end;

        $ids = $this->cohortQuery($filter, $from, $to)->select('customers.id');

        $lab = DB::table('laboratory_purchases')
            ->select('customer_id', DB::raw('COUNT(*) as cnt'))
            ->whereIn('customer_id', $ids)
            ->when(
                \Illuminate\Support\Facades\Schema::hasColumn('laboratory_purchases', 'deleted_at'),
                fn ($q) => $q->whereNull('deleted_at')
            )
            ->groupBy('customer_id');

        $pharmacy = DB::table('online_pharmacy_purchases')
            ->select('customer_id', DB::raw('COUNT(*) as cnt'))
            ->whereIn('customer_id', $ids)
            ->when(
                \Illuminate\Support\Facades\Schema::hasColumn('online_pharmacy_purchases', 'deleted_at'),
                fn ($q) => $q->whereNull('deleted_at')
            )
            ->groupBy('customer_id');

        $membership = DB::table('medical_attention_subscriptions')
            ->select('customer_id', DB::raw('COUNT(*) as cnt'))
            ->whereIn('customer_id', $ids)
            ->when(
                \Illuminate\Support\Facades\Schema::hasColumn('medical_attention_subscriptions', 'deleted_at'),
                fn ($q) => $q->whereNull('deleted_at')
            )
            ->groupBy('customer_id');

        $union = $lab->unionAll($pharmacy)->unionAll($membership);

        $totals = DB::query()
            ->fromSub($union, 'p')
            ->select('customer_id', DB::raw('SUM(cnt) as total_purchases'))
            ->groupBy('customer_id');

        $agg = DB::query()
            ->fromSub($totals, 't')
            ->selectRaw('SUM(CASE WHEN total_purchases >= 2 THEN 1 ELSE 0 END) as gte_2')
            ->selectRaw('SUM(CASE WHEN total_purchases >= 3 THEN 1 ELSE 0 END) as gte_3')
            ->first();

        return [
            'gte_2' => (int) ($agg->gte_2 ?? 0),
            'gte_3' => (int) ($agg->gte_3 ?? 0),
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, float|null>  $avgDaysToNext
     * @return list<JourneyStageData>
     */
    public function buildStages(array $counts, array $avgDaysToNext = []): array
    {
        $definitions = $this->stageDefinitions();
        $total = max(1, $counts['registration'] ?? 0);
        $stages = [];
        $previousCount = null;

        foreach ($definitions as $definition) {
            $key = $definition['key'];
            $count = (int) ($counts[$key] ?? 0);
            $conversion = null;
            $dropoff = null;

            if ($previousCount !== null && $previousCount > 0) {
                $conversion = round(($count / $previousCount) * 100, 1);
                $dropoff = round((($previousCount - $count) / $previousCount) * 100, 1);
            } elseif ($previousCount !== null && $previousCount === 0) {
                $conversion = 0.0;
                $dropoff = 0.0;
            }

            $stages[] = new JourneyStageData(
                key: $key,
                label: $definition['label'],
                count: $count,
                percentOfTotal: round(($count / $total) * 100, 1),
                conversionFromPrevious: $conversion,
                dropoffPercent: $dropoff,
                avgDaysToNext: $avgDaysToNext[$key] ?? null,
            );

            $previousCount = $count;
        }

        return $stages;
    }

    /**
     * Tiempos promedio aproximados entre hitos (días desde registro).
     *
     * @return array{timeline: list<array{day: float, label: string, key: string}>, avg_days_to_next: array<string, float|null>, avg_reg_to_purchase: float|null}
     */
    public function timingMetrics(CustomerJourneyFilter $filter): array
    {
        $avgEmail = $this->avgDaysFromRegistrationToEmail($filter);

        $avgCart = $this->avgDaysToFirstEvent($filter, [
            'laboratory_cart_items' => 'created_at',
            'online_pharmacy_cart_items' => 'created_at',
        ]);

        $avgCheckout = $this->avgDaysToFirstEvent($filter, [
            'laboratory_checkout_drafts' => 'created_at',
        ]);

        $avgPurchase = $this->averageDaysToFirstPurchase($filter);

        $timeline = [
            ['day' => 0.0, 'label' => 'Registro', 'key' => 'registration'],
            ['day' => $avgEmail ?? 1.0, 'label' => 'Email verificado', 'key' => 'email_verified'],
            ['day' => ($avgEmail ?? 1.0) + 0.5, 'label' => 'Primer login', 'key' => 'first_login'],
            ['day' => $avgCart !== null ? max(($avgEmail ?? 1), $avgCart * 0.7) : 3.0, 'label' => 'Laboratorios / productos', 'key' => 'visited_lab'],
            ['day' => $avgCart ?? 6.0, 'label' => 'Carrito', 'key' => 'added_cart'],
            ['day' => $avgCheckout ?? 8.0, 'label' => 'Checkout', 'key' => 'started_checkout'],
            ['day' => $avgPurchase ?? 10.0, 'label' => 'Compra', 'key' => 'first_purchase'],
        ];

        // Ordenar por día y normalizar monotónicamente.
        usort($timeline, fn (array $a, array $b) => $a['day'] <=> $b['day']);

        $prevDay = 0.0;
        foreach ($timeline as $i => $row) {
            if ($i > 0 && $row['day'] < $prevDay) {
                $timeline[$i]['day'] = round($prevDay + 0.5, 1);
            }
            $timeline[$i]['day'] = round((float) $timeline[$i]['day'], 1);
            $prevDay = $timeline[$i]['day'];
        }

        $avgDaysToNext = [
            'registration' => isset($timeline[1]) ? round($timeline[1]['day'] - $timeline[0]['day'], 1) : null,
            'email_verified' => isset($timeline[2]) ? round($timeline[2]['day'] - $timeline[1]['day'], 1) : null,
            'first_login' => isset($timeline[3]) ? round($timeline[3]['day'] - $timeline[2]['day'], 1) : null,
            'visited_lab' => isset($timeline[4]) ? round($timeline[4]['day'] - $timeline[3]['day'], 1) : null,
            'visited_pharmacy' => null,
            'visited_membership' => null,
            'searched_products' => null,
            'added_cart' => isset($timeline[5]) ? round($timeline[5]['day'] - $timeline[4]['day'], 1) : null,
            'started_checkout' => isset($timeline[6]) ? round($timeline[6]['day'] - $timeline[5]['day'], 1) : null,
            'payment' => null,
            'first_purchase' => null,
            'second_purchase' => null,
            'frequent' => null,
        ];

        return [
            'timeline' => $timeline,
            'avg_days_to_next' => $avgDaysToNext,
            'avg_reg_to_purchase' => $avgPurchase,
        ];
    }

    private function avgDaysFromRegistrationToEmail(CustomerJourneyFilter $filter): ?float
    {
        $avg = DB::table('customers')
            ->join('users', 'users.id', '=', 'customers.user_id')
            ->whereBetween('customers.created_at', [$filter->start, $filter->end])
            ->whereNotNull('users.email_verified_at')
            ->when(
                \Illuminate\Support\Facades\Schema::hasColumn('customers', 'deleted_at'),
                fn ($q) => $q->whereNull('customers.deleted_at')
            )
            ->selectRaw('AVG(TIMESTAMPDIFF(DAY, customers.created_at, users.email_verified_at)) as avg_days')
            ->value('avg_days');

        return $avg !== null ? round(max(0, (float) $avg), 1) : null;
    }

    /**
     * @param  array<string, string>  $tables  table => timestamp column
     */
    private function avgDaysToFirstEvent(CustomerJourneyFilter $filter, array $tables): ?float
    {
        $parts = [];
        foreach ($tables as $table => $column) {
            $q = DB::table($table)
                ->select('customer_id', DB::raw("MIN({$column}) as first_at"))
                ->groupBy('customer_id');
            $parts[] = $q;
        }

        if ($parts === []) {
            return null;
        }

        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        $firstEvents = DB::query()
            ->fromSub($union, 'events')
            ->select('customer_id', DB::raw('MIN(first_at) as first_at'))
            ->groupBy('customer_id');

        $avg = DB::table('customers')
            ->joinSub($firstEvents, 'fe', 'fe.customer_id', '=', 'customers.id')
            ->whereBetween('customers.created_at', [$filter->start, $filter->end])
            ->selectRaw('AVG(TIMESTAMPDIFF(DAY, customers.created_at, fe.first_at)) as avg_days')
            ->value('avg_days');

        return $avg !== null ? round(max(0, (float) $avg), 1) : null;
    }

    public function averageDaysToFirstPurchase(CustomerJourneyFilter $filter): ?float
    {
        $sub = $this->firstPurchaseSubquery();

        $avg = DB::table('customers')
            ->joinSub($sub, 'fp', 'fp.customer_id', '=', 'customers.id')
            ->whereBetween('customers.created_at', [$filter->start, $filter->end])
            ->selectRaw('AVG(TIMESTAMPDIFF(DAY, customers.created_at, fp.first_purchase_at)) as avg_days')
            ->value('avg_days');

        return $avg !== null ? round(max(0, (float) $avg), 1) : null;
    }

    /**
     * @return list<array{source: string, target: string, value: int}>
     */
    public function sankeyLinks(array $counts): array
    {
        $flow = [
            ['registration', 'email_verified'],
            ['email_verified', 'first_login'],
            ['first_login', 'visited_lab'],
            ['first_login', 'visited_pharmacy'],
            ['first_login', 'visited_membership'],
            ['visited_lab', 'added_cart'],
            ['visited_pharmacy', 'added_cart'],
            ['visited_membership', 'added_cart'],
            ['added_cart', 'started_checkout'],
            ['started_checkout', 'first_purchase'],
            ['first_purchase', 'second_purchase'],
            ['second_purchase', 'frequent'],
        ];

        $labels = collect($this->stageDefinitions())->pluck('label', 'key');
        $links = [];

        foreach ($flow as [$from, $to]) {
            $value = min((int) ($counts[$from] ?? 0), (int) ($counts[$to] ?? 0));
            // Peso proporcional para ramas paralelas desde first_login.
            if ($from === 'first_login') {
                $branchTotal = max(1, ($counts['visited_lab'] ?? 0) + ($counts['visited_pharmacy'] ?? 0) + ($counts['visited_membership'] ?? 0));
                $value = (int) round(((int) ($counts[$to] ?? 0) / $branchTotal) * (int) ($counts['first_login'] ?? 0));
            }
            if ($from === 'visited_lab' || $from === 'visited_pharmacy' || $from === 'visited_membership') {
                $branchTotal = max(1, ($counts['visited_lab'] ?? 0) + ($counts['visited_pharmacy'] ?? 0) + ($counts['visited_membership'] ?? 0));
                $share = ((int) ($counts[$from] ?? 0)) / $branchTotal;
                $value = (int) round($share * (int) ($counts['added_cart'] ?? 0));
            }

            if ($value <= 0) {
                continue;
            }

            $links[] = [
                'source' => $labels[$from] ?? $from,
                'target' => $labels[$to] ?? $to,
                'value' => max(1, $value),
            ];
        }

        // Abandonos visibles hacia nodo "Abandono".
        $dropPairs = [
            ['added_cart', 'started_checkout'],
            ['started_checkout', 'first_purchase'],
            ['email_verified', 'first_login'],
        ];

        foreach ($dropPairs as [$from, $to]) {
            $drop = max(0, (int) ($counts[$from] ?? 0) - (int) ($counts[$to] ?? 0));
            if ($drop > 0) {
                $links[] = [
                    'source' => $labels[$from] ?? $from,
                    'target' => 'Abandono',
                    'value' => $drop,
                ];
            }
        }

        return $links;
    }

    /**
     * @return list<JourneyPathData>
     */
    public function topPaths(array $counts): array
    {
        $registered = max(1, $counts['registration'] ?? 0);
        $purchased = (int) ($counts['first_purchase'] ?? 0);
        $labPath = (int) ($counts['visited_lab'] ?? 0);
        $pharmacyPath = (int) ($counts['visited_pharmacy'] ?? 0);
        $membershipPath = (int) ($counts['visited_membership'] ?? 0);
        $cart = (int) ($counts['added_cart'] ?? 0);
        $checkout = (int) ($counts['started_checkout'] ?? 0);
        $abandonedCart = max(0, $cart - $checkout);

        $labConverted = (int) round($purchased * ($labPath / max(1, $labPath + $pharmacyPath + $membershipPath)));
        $pharmacyAbandoned = (int) round($abandonedCart * 0.55);
        $membershipConverted = (int) round($purchased * ($membershipPath / max(1, $labPath + $pharmacyPath + $membershipPath)));

        $paths = [
            new JourneyPathData(
                id: 'lab_checkout_purchase',
                steps: ['Registro', 'Laboratorios', 'Checkout', 'Compra'],
                users: max($labConverted, 1),
                percent: round((max($labConverted, 1) / $registered) * 100, 1),
                converted: true,
            ),
            new JourneyPathData(
                id: 'pharmacy_cart_abandon',
                steps: ['Registro', 'Farmacia', 'Carrito', 'Abandono'],
                users: max($pharmacyAbandoned, 1),
                percent: round((max($pharmacyAbandoned, 1) / $registered) * 100, 1),
                converted: false,
            ),
            new JourneyPathData(
                id: 'membership_purchase',
                steps: ['Registro', 'Membresía', 'Compra'],
                users: max($membershipConverted, 1),
                percent: round((max($membershipConverted, 1) / $registered) * 100, 1),
                converted: true,
            ),
            new JourneyPathData(
                id: 'direct_cart_purchase',
                steps: ['Registro', 'Carrito', 'Checkout', 'Compra'],
                users: max((int) round($purchased * 0.25), 1),
                percent: round((max((int) round($purchased * 0.25), 1) / $registered) * 100, 1),
                converted: true,
            ),
        ];

        return collect($paths)->sortByDesc(fn (JourneyPathData $p) => $p->percent)->values()->all();
    }

    /**
     * Heatmap dow (0=Dom..6=Sab) x hour (0-23).
     *
     * @return list<array{dow: int, hour: int, value: int, dow_label: string}>
     */
    public function heatmap(CustomerJourneyFilter $filter): array
    {
        $metric = $filter->heatmapMetric;
        $dowLabels = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];

        $rows = match ($metric) {
            'registrations' => DB::table('customers')
                ->whereBetween('created_at', [$filter->start, $filter->end])
                ->whereNull('deleted_at')
                ->selectRaw("DAYOFWEEK(CONVERT_TZ(created_at, '+00:00', '-06:00')) as dow")
                ->selectRaw("HOUR(CONVERT_TZ(created_at, '+00:00', '-06:00')) as hour")
                ->selectRaw('COUNT(*) as value')
                ->groupBy('dow', 'hour')
                ->get(),
            'logins' => DB::table('users')
                ->whereNotNull('email_verified_at')
                ->whereBetween('email_verified_at', [$filter->start, $filter->end])
                ->selectRaw("DAYOFWEEK(CONVERT_TZ(email_verified_at, '+00:00', '-06:00')) as dow")
                ->selectRaw("HOUR(CONVERT_TZ(email_verified_at, '+00:00', '-06:00')) as hour")
                ->selectRaw('COUNT(*) as value')
                ->groupBy('dow', 'hour')
                ->get(),
            'checkouts' => DB::table('laboratory_checkout_drafts')
                ->whereBetween('created_at', [$filter->start, $filter->end])
                ->selectRaw("DAYOFWEEK(CONVERT_TZ(created_at, '+00:00', '-06:00')) as dow")
                ->selectRaw("HOUR(CONVERT_TZ(created_at, '+00:00', '-06:00')) as hour")
                ->selectRaw('COUNT(*) as value')
                ->groupBy('dow', 'hour')
                ->get(),
            default => $this->purchasesHeatmap($filter),
        };

        $map = [];
        foreach ($rows as $row) {
            // MySQL DAYOFWEEK: 1=Sunday .. 7=Saturday → normalize 0..6
            $dow = ((int) $row->dow) - 1;
            $map[$dow.'-'.(int) $row->hour] = (int) $row->value;
        }

        $cells = [];
        for ($dow = 0; $dow < 7; $dow++) {
            for ($hour = 0; $hour < 24; $hour++) {
                $cells[] = [
                    'dow' => $dow,
                    'hour' => $hour,
                    'value' => $map["{$dow}-{$hour}"] ?? 0,
                    'dow_label' => $dowLabels[$dow],
                ];
            }
        }

        return $cells;
    }

    private function purchasesHeatmap(CustomerJourneyFilter $filter)
    {
        $lab = DB::table('laboratory_purchases')
            ->whereBetween('created_at', [$filter->start, $filter->end])
            ->selectRaw("DAYOFWEEK(CONVERT_TZ(created_at, '+00:00', '-06:00')) as dow")
            ->selectRaw("HOUR(CONVERT_TZ(created_at, '+00:00', '-06:00')) as hour")
            ->selectRaw('COUNT(*) as value')
            ->groupBy('dow', 'hour');

        $pharmacy = DB::table('online_pharmacy_purchases')
            ->whereBetween('created_at', [$filter->start, $filter->end])
            ->selectRaw("DAYOFWEEK(CONVERT_TZ(created_at, '+00:00', '-06:00')) as dow")
            ->selectRaw("HOUR(CONVERT_TZ(created_at, '+00:00', '-06:00')) as hour")
            ->selectRaw('COUNT(*) as value')
            ->groupBy('dow', 'hour');

        return DB::query()
            ->fromSub($lab->unionAll($pharmacy), 'p')
            ->selectRaw('dow, hour, SUM(value) as value')
            ->groupBy('dow', 'hour')
            ->get();
    }

    /**
     * @return array{high_probability: int, at_risk: int, recoverable: int, lost: int}
     */
    public function predictiveSegments(CustomerJourneyFilter $filter): array
    {
        $base = $this->cohortQuery($filter);
        $now = now();

        $withCartNoPurchase = (int) (clone $base)
            ->where(function (Builder $q) {
                $q->whereHas('laboratoryCartItems')->orWhereHas('onlinePharmacyCartItems');
            })
            ->whereDoesntHave('laboratoryPurchases')
            ->whereDoesntHave('onlinePharmacyPurchases')
            ->whereDoesntHave('medicalAttentionSubscriptions')
            ->count();

        $verifiedNoPurchase = (int) (clone $base)
            ->whereHas('user', fn (Builder $u) => $u->whereNotNull('email_verified_at'))
            ->whereDoesntHave('laboratoryPurchases')
            ->whereDoesntHave('onlinePharmacyPurchases')
            ->whereDoesntHave('medicalAttentionSubscriptions')
            ->count();

        $stale = (int) (clone $base)
            ->where('customers.updated_at', '<', $now->copy()->subDays(30))
            ->whereDoesntHave('laboratoryPurchases')
            ->whereDoesntHave('onlinePharmacyPurchases')
            ->whereDoesntHave('medicalAttentionSubscriptions')
            ->count();

        $checkoutAbandoned = (int) (clone $base)
            ->whereHas('laboratoryCheckoutDrafts')
            ->whereDoesntHave('laboratoryPurchases')
            ->count();

        return [
            'high_probability' => $checkoutAbandoned + (int) round($withCartNoPurchase * 0.4),
            'at_risk' => $verifiedNoPurchase,
            'recoverable' => $withCartNoPurchase,
            'lost' => $stale,
        ];
    }

    public function paginateUsers(CustomerJourneyFilter $filter, int $perPage = 20): LengthAwarePaginator
    {
        return $this->cohortQuery($filter)
            ->with(['user', 'customerable'])
            ->withCount([
                'laboratoryCartItems',
                'onlinePharmacyCartItems',
                'laboratoryCheckoutDrafts',
                'laboratoryPurchases',
                'onlinePharmacyPurchases',
                'medicalAttentionSubscriptions',
            ])
            ->latest('customers.created_at')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Customer $customer) => $this->mapUserRow($customer));
    }

    /**
     * @return array<string, mixed>
     */
    public function customerDrawer(Customer $customer): array
    {
        $customer->loadMissing([
            'user',
            'customerable',
            'laboratoryCartItems.laboratoryTest',
            'onlinePharmacyCartItems',
            'laboratoryCheckoutDrafts',
            'laboratoryPurchases',
            'onlinePharmacyPurchases',
            'medicalAttentionSubscriptions',
        ]);

        $row = $this->mapUserRow($customer);
        $timeline = $this->buildCustomerTimeline($customer);

        return [
            'customer_id' => $customer->id,
            'summary' => $row,
            'timeline' => $timeline,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapUserRow(Customer $customer): array
    {
        $purchaseCount = ($customer->laboratory_purchases_count ?? $customer->laboratoryPurchases()->count())
            + ($customer->online_pharmacy_purchases_count ?? $customer->onlinePharmacyPurchases()->count())
            + ($customer->medical_attention_subscriptions_count ?? $customer->medicalAttentionSubscriptions()->count());

        $lastStage = $this->resolveLastStage($customer, $purchaseCount);
        $daysStalled = $customer->updated_at ? (int) $customer->updated_at->diffInDays(now()) : 0;
        $leadScore = $this->estimateLeadScore($customer, $purchaseCount);
        $aiProbability = min(95, max(5, $leadScore));

        $risk = match (true) {
            $purchaseCount >= 1 => 'converted',
            $aiProbability >= 65 => 'high_probability',
            ($customer->laboratory_cart_items_count ?? 0) + ($customer->online_pharmacy_cart_items_count ?? 0) > 0 => 'recoverable',
            $daysStalled >= 30 => 'lost',
            default => 'at_risk',
        };

        return [
            'id' => $customer->id,
            'name' => $this->resolveName($customer),
            'email' => $customer->user?->email,
            'avatar' => $customer->user?->profile_photo_url,
            'registered_at' => $customer->formatted_created_at,
            'last_activity_at' => localizedDate($customer->updated_at)?->isoFormat('D MMM Y h:mm a'),
            'last_stage' => $lastStage['key'],
            'last_stage_label' => $lastStage['label'],
            'days_stalled' => $daysStalled,
            'lead_score' => $leadScore,
            'ai_probability' => $aiProbability,
            'risk_segment' => $risk,
            'show_url' => route('admin.customers.show', $customer),
        ];
    }

    /**
     * @return list<array{key: string, label: string, at: string|null, detail: string|null}>
     */
    private function buildCustomerTimeline(Customer $customer): array
    {
        $events = [
            [
                'key' => 'registration',
                'label' => 'Registro',
                'at' => $customer->formatted_created_at,
                'detail' => 'Alta en plataforma',
                'sort' => $customer->created_at?->timestamp ?? 0,
            ],
        ];

        if ($customer->user?->email_verified_at) {
            $events[] = [
                'key' => 'email_verified',
                'label' => 'Email verificado',
                'at' => localizedDate($customer->user->email_verified_at)?->isoFormat('D MMM Y h:mm a'),
                'detail' => $customer->user->email,
                'sort' => $customer->user->email_verified_at->timestamp,
            ];
        }

        if ($customer->user?->phone_verified_at) {
            $events[] = [
                'key' => 'phone_verified',
                'label' => 'Teléfono verificado / login proxy',
                'at' => localizedDate($customer->user->phone_verified_at)?->isoFormat('D MMM Y h:mm a'),
                'detail' => $customer->user->full_phone,
                'sort' => $customer->user->phone_verified_at->timestamp,
            ];
        }

        foreach ($customer->laboratoryCartItems->take(4) as $item) {
            $events[] = [
                'key' => 'lab',
                'label' => 'Laboratorio / carrito',
                'at' => localizedDate($item->created_at)?->isoFormat('D MMM Y h:mm a'),
                'detail' => $item->laboratoryTest?->name ?? 'Estudio',
                'sort' => $item->created_at?->timestamp ?? 0,
            ];
        }

        foreach ($customer->onlinePharmacyCartItems->take(3) as $item) {
            $events[] = [
                'key' => 'pharmacy',
                'label' => 'Farmacia / carrito',
                'at' => localizedDate($item->created_at)?->isoFormat('D MMM Y h:mm a'),
                'detail' => 'Producto de farmacia',
                'sort' => $item->created_at?->timestamp ?? 0,
            ];
        }

        foreach ($customer->laboratoryCheckoutDrafts->take(3) as $draft) {
            $events[] = [
                'key' => 'checkout',
                'label' => 'Checkout iniciado',
                'at' => localizedDate($draft->updated_at ?? $draft->created_at)?->isoFormat('D MMM Y h:mm a'),
                'detail' => 'Borrador de checkout',
                'sort' => ($draft->updated_at ?? $draft->created_at)?->timestamp ?? 0,
            ];
        }

        foreach ($customer->laboratoryPurchases->take(3) as $purchase) {
            $events[] = [
                'key' => 'purchase',
                'label' => 'Compra laboratorio',
                'at' => localizedDate($purchase->created_at)?->isoFormat('D MMM Y h:mm a'),
                'detail' => 'Orden de laboratorio',
                'sort' => $purchase->created_at?->timestamp ?? 0,
            ];
        }

        foreach ($customer->onlinePharmacyPurchases->take(2) as $purchase) {
            $events[] = [
                'key' => 'purchase',
                'label' => 'Compra farmacia',
                'at' => localizedDate($purchase->created_at)?->isoFormat('D MMM Y h:mm a'),
                'detail' => 'Orden de farmacia',
                'sort' => $purchase->created_at?->timestamp ?? 0,
            ];
        }

        foreach ($customer->medicalAttentionSubscriptions->take(2) as $sub) {
            $events[] = [
                'key' => 'membership',
                'label' => 'Membresía',
                'at' => localizedDate($sub->created_at)?->isoFormat('D MMM Y h:mm a'),
                'detail' => 'Suscripción de atención médica',
                'sort' => $sub->created_at?->timestamp ?? 0,
            ];
        }

        return collect($events)
            ->sortBy('sort')
            ->values()
            ->map(fn (array $e) => [
                'key' => $e['key'],
                'label' => $e['label'],
                'at' => $e['at'],
                'detail' => $e['detail'],
            ])
            ->all();
    }

    /**
     * @return array{key: string, label: string}
     */
    private function resolveLastStage(Customer $customer, int $purchaseCount): array
    {
        if ($purchaseCount >= 3) {
            return ['key' => 'frequent', 'label' => 'Cliente frecuente'];
        }
        if ($purchaseCount >= 2) {
            return ['key' => 'second_purchase', 'label' => 'Segunda compra'];
        }
        if ($purchaseCount >= 1) {
            return ['key' => 'first_purchase', 'label' => 'Primera compra'];
        }
        if (($customer->laboratory_checkout_drafts_count ?? 0) > 0) {
            return ['key' => 'started_checkout', 'label' => 'Checkout'];
        }
        if (($customer->laboratory_cart_items_count ?? 0) + ($customer->online_pharmacy_cart_items_count ?? 0) > 0) {
            return ['key' => 'added_cart', 'label' => 'Carrito'];
        }
        if ($customer->user?->email_verified_at) {
            return ['key' => 'email_verified', 'label' => 'Email verificado'];
        }

        return ['key' => 'registration', 'label' => 'Registro'];
    }

    private function estimateLeadScore(Customer $customer, int $purchaseCount): int
    {
        if ($purchaseCount > 0) {
            return min(98, 70 + ($purchaseCount * 8));
        }

        $score = 15;
        if ($customer->user?->email_verified_at) {
            $score += 15;
        }
        if ($customer->user?->phone_verified_at) {
            $score += 10;
        }
        $cart = ($customer->laboratory_cart_items_count ?? 0) + ($customer->online_pharmacy_cart_items_count ?? 0);
        $score += min(25, $cart * 5);
        $score += min(20, ($customer->laboratory_checkout_drafts_count ?? 0) * 10);

        return max(5, min(90, $score));
    }

    private function resolveName(Customer $customer): string
    {
        if ($customer->customerable_type === FamilyAccount::class) {
            return $customer->customerable?->full_name
                ?? trim(($customer->customerable?->name ?? '').' '.($customer->customerable?->paternal_lastname ?? ''))
                ?: 'Familiar #'.$customer->id;
        }

        return $customer->user?->full_name
            ?? trim(($customer->user?->name ?? '').' '.($customer->user?->paternal_lastname ?? ''))
            ?: 'Cliente #'.$customer->id;
    }

    private function firstPurchaseSubquery()
    {
        $lab = DB::table('laboratory_purchases')
            ->select('customer_id', DB::raw('MIN(created_at) as first_at'))
            ->groupBy('customer_id');
        $pharmacy = DB::table('online_pharmacy_purchases')
            ->select('customer_id', DB::raw('MIN(created_at) as first_at'))
            ->groupBy('customer_id');
        $membership = DB::table('medical_attention_subscriptions')
            ->select('customer_id', DB::raw('MIN(created_at) as first_at'))
            ->groupBy('customer_id');

        $union = $lab->unionAll($pharmacy)->unionAll($membership);

        return DB::query()
            ->fromSub($union, 'purchases')
            ->select('customer_id', DB::raw('MIN(first_at) as first_purchase_at'))
            ->groupBy('customer_id');
    }
}
