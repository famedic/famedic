<?php

namespace App\Services\ActiveCampaign;

use App\Enums\MedicalSubscriptionType;
use App\Enums\MonitoringCartStatus;
use App\Models\Cart;
use App\Models\CouponTransaction;
use App\Models\LaboratoryPurchase;
use App\Models\MedicalAttentionSubscription;
use App\Models\OnlinePharmacyPurchase;
use App\Support\ActiveCampaign\ActiveCampaignDashboardFilter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ecommerce Intelligence — consola ejecutiva comercial (Lab + Farmacia + Membresías).
 * No modifica módulos aprobados ni CartsDashboard; lee modelos existentes.
 */
class ActiveCampaignEcommerceIntelligenceService
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
            $channels = $this->channelStats($filter->start, $filter->end);
            $channelsPrev = $this->channelStats($filter->previousStart, $filter->previousEnd);
            $carts = $this->cartStats($filter->start, $filter->end);
            $cartsPrev = $this->cartStats($filter->previousStart, $filter->previousEnd);
            $discounts = $this->discountStats($filter->start, $filter->end);
            $discountsPrev = $this->discountStats($filter->previousStart, $filter->previousEnd);

            $kpis = $this->buildExecutiveKpis($channels, $channelsPrev, $carts, $cartsPrev, $discounts, $discountsPrev);
            $distribution = $this->buildDistribution($channels);
            $payments = $this->buildPaymentMethods($filter);
            $topProducts = $this->buildTopProducts($filter);
            $coupons = $this->buildCoupons($filter);
            $overview = $this->dashboard->buildOverview($filter);
            $decision = $this->buildDecision($filter, $kpis, $distribution, $carts, $overview);

            return [
                'filters' => $filter->toArray(),
                'summary' => $kpis['cards'],
                'kpis' => $kpis['business'],
                'distribution' => $distribution,
                'payment_methods' => $payments,
                'top_products' => $topProducts,
                'coupons' => $coupons,
                'insights' => $decision['insights'],
                'recommendations' => $decision['recommendations'],
                'risks' => $decision['risks'],
                'suggested_actions' => $this->suggestedActions(),
                'gaps' => $this->gaps(),
                'meta' => [
                    ...($overview['meta'] ?? []),
                    'purpose' => 'Consola ejecutiva de Dirección: consolidar ventas Lab, Farmacia y Membresías.',
                    'source_of_truth' => 'LaboratoryPurchase · OnlinePharmacyPurchase · MedicalAttentionSubscription · Cart · CouponTransaction · cruce Dashboard/Analytics',
                    'note' => 'GMV = suma de totales/price_cents por canal. Neto = GMV − cupones Famedic (proxy; sin COGS).',
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
            'by_channel' => $this->seriesByChannel($filter),
        ];

        if ($filter->bustCache) {
            Cache::forget($this->cacheKey($filter, 'charts'));
        }

        return Cache::remember($this->cacheKey($filter, 'charts'), now()->addMinutes(5), $resolver);
    }

    private function cacheKey(ActiveCampaignDashboardFilter $filter, string $suffix): string
    {
        return 'mi-ecom-intel:v1:'.sha1(json_encode($filter->toArray()).'|'.$suffix);
    }

    private function labActivityExpr(string $alias = ''): string
    {
        $p = $alias !== '' ? $alias.'.' : '';
        if (Schema::hasColumn('laboratory_purchases', 'paid_at')) {
            return "COALESCE({$p}paid_at, {$p}completed_at, {$p}created_at)";
        }

        return "{$p}created_at";
    }

    /**
     * @return array{
     *     lab: array{orders: int, gmv_cents: int, discount_cents: int},
     *     pharmacy: array{orders: int, gmv_cents: int, discount_cents: int},
     *     membership: array{orders: int, gmv_cents: int, discount_cents: int}
     * }
     */
    private function channelStats(Carbon $start, Carbon $end): array
    {
        $startS = $start->toDateTimeString();
        $endS = $end->toDateTimeString();
        $labExpr = $this->labActivityExpr();

        $lab = LaboratoryPurchase::query()
            ->toBase()
            ->whereNull('deleted_at')
            ->whereRaw("{$labExpr} BETWEEN ? AND ?", [$startS, $endS])
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(total_cents), 0) as gmv_cents')
            ->selectRaw(
                Schema::hasColumn('laboratory_purchases', 'coupon_discount_cents')
                    ? 'COALESCE(SUM(coupon_discount_cents), 0) as discount_cents'
                    : '0 as discount_cents'
            )
            ->first();

        $pharmacyDiscountSql = Schema::hasColumn('online_pharmacy_purchases', 'coupon_discount_cents')
            ? 'COALESCE(SUM(coupon_discount_cents), 0)'
            : '0';
        if (Schema::hasColumn('online_pharmacy_purchases', 'discount_cents')) {
            $pharmacyDiscountSql = "({$pharmacyDiscountSql} + COALESCE(SUM(discount_cents), 0))";
        }

        $pharmacy = OnlinePharmacyPurchase::query()
            ->toBase()
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$startS, $endS])
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(total_cents), 0) as gmv_cents')
            ->selectRaw("{$pharmacyDiscountSql} as discount_cents")
            ->first();

        $membership = MedicalAttentionSubscription::query()
            ->toBase()
            ->whereNull('deleted_at')
            ->where('type', '!=', MedicalSubscriptionType::FAMILY_MEMBER->value)
            ->whereBetween('created_at', [$startS, $endS])
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(price_cents), 0) as gmv_cents')
            ->selectRaw('0 as discount_cents')
            ->first();

        return [
            'lab' => [
                'orders' => (int) ($lab->orders ?? 0),
                'gmv_cents' => (int) ($lab->gmv_cents ?? 0),
                'discount_cents' => (int) ($lab->discount_cents ?? 0),
            ],
            'pharmacy' => [
                'orders' => (int) ($pharmacy->orders ?? 0),
                'gmv_cents' => (int) ($pharmacy->gmv_cents ?? 0),
                'discount_cents' => (int) ($pharmacy->discount_cents ?? 0),
            ],
            'membership' => [
                'orders' => (int) ($membership->orders ?? 0),
                'gmv_cents' => (int) ($membership->gmv_cents ?? 0),
                'discount_cents' => 0,
            ],
        ];
    }

    /**
     * @return array{created: int, completed: int, abandoned: int, abandoned_value: float, conversion: float|null}
     */
    private function cartStats(Carbon $start, Carbon $end): array
    {
        $startS = $start->toDateTimeString();
        $endS = $end->toDateTimeString();
        $staleBefore = now()->subMinutes(Cart::ABANDONED_AFTER_MINUTES);

        $created = (int) Cart::query()
            ->whereBetween('created_at', [$startS, $endS])
            ->count();

        $completed = (int) Cart::query()
            ->where('status', MonitoringCartStatus::Completed->value)
            ->where(function ($q) use ($startS, $endS) {
                $q->whereBetween('completed_at', [$startS, $endS])
                    ->orWhere(function ($fallback) use ($startS, $endS) {
                        $fallback->whereNull('completed_at')
                            ->whereBetween('updated_at', [$startS, $endS]);
                    });
            })
            ->count();

        $abandonedQuery = Cart::query()
            ->where('status', MonitoringCartStatus::Active->value)
            ->where('updated_at', '<', $staleBefore)
            ->whereBetween('updated_at', [$startS, $endS]);

        $abandoned = (int) (clone $abandonedQuery)->count();
        $abandonedValue = (float) (clone $abandonedQuery)->sum('total');

        $denom = $completed + $abandoned;
        $conversion = $denom > 0 ? round(100 * $completed / $denom, 1) : null;

        return [
            'created' => $created,
            'completed' => $completed,
            'abandoned' => $abandoned,
            'abandoned_value' => round($abandonedValue, 2),
            'conversion' => $conversion,
        ];
    }

    /**
     * @return array{uses: int, amount_cents: int}
     */
    private function discountStats(Carbon $start, Carbon $end): array
    {
        $row = CouponTransaction::query()
            ->notReversed()
            ->whereBetween('created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->toBase()
            ->selectRaw('COUNT(*) as uses')
            ->selectRaw('COALESCE(SUM(amount_used_cents), 0) as amount_cents')
            ->first();

        return [
            'uses' => (int) ($row->uses ?? 0),
            'amount_cents' => (int) ($row->amount_cents ?? 0),
        ];
    }

    /**
     * @param  array<string, array{orders: int, gmv_cents: int, discount_cents: int}>  $channels
     * @param  array<string, array{orders: int, gmv_cents: int, discount_cents: int}>  $channelsPrev
     * @param  array{created: int, completed: int, abandoned: int, abandoned_value: float, conversion: float|null}  $carts
     * @param  array{created: int, completed: int, abandoned: int, abandoned_value: float, conversion: float|null}  $cartsPrev
     * @param  array{uses: int, amount_cents: int}  $discounts
     * @param  array{uses: int, amount_cents: int}  $discountsPrev
     * @return array{cards: list<array<string, mixed>>, business: list<array<string, mixed>>}
     */
    private function buildExecutiveKpis(
        array $channels,
        array $channelsPrev,
        array $carts,
        array $cartsPrev,
        array $discounts,
        array $discountsPrev,
    ): array {
        $gmv = $channels['lab']['gmv_cents'] + $channels['pharmacy']['gmv_cents'] + $channels['membership']['gmv_cents'];
        $gmvPrev = $channelsPrev['lab']['gmv_cents'] + $channelsPrev['pharmacy']['gmv_cents'] + $channelsPrev['membership']['gmv_cents'];

        $channelDiscounts = $channels['lab']['discount_cents'] + $channels['pharmacy']['discount_cents'];
        $channelDiscountsPrev = $channelsPrev['lab']['discount_cents'] + $channelsPrev['pharmacy']['discount_cents'];

        // Neto proxy: GMV − descuentos en purchase (cupón + Vitau pharmacy). Ledger CouponTransaction como cruce.
        $net = max(0, $gmv - $channelDiscounts);
        $netPrev = max(0, $gmvPrev - $channelDiscountsPrev);

        $ordersProduct = $channels['lab']['orders'] + $channels['pharmacy']['orders'];
        $ordersProductPrev = $channelsPrev['lab']['orders'] + $channelsPrev['pharmacy']['orders'];
        $ordersAll = $ordersProduct + $channels['membership']['orders'];
        $ordersAllPrev = $ordersProductPrev + $channelsPrev['membership']['orders'];

        $gmvProduct = $channels['lab']['gmv_cents'] + $channels['pharmacy']['gmv_cents'];
        $gmvProductPrev = $channelsPrev['lab']['gmv_cents'] + $channelsPrev['pharmacy']['gmv_cents'];
        $ticket = $ordersProduct > 0 ? (int) round($gmvProduct / $ordersProduct) : 0;
        $ticketPrev = $ordersProductPrev > 0 ? (int) round($gmvProductPrev / $ordersProductPrev) : 0;

        $discountDisplay = max($discounts['amount_cents'], $channelDiscounts);

        $cards = [
            $this->metricCard('gmv', 'GMV', $this->money($gmv), 'disponible', 'lime', 'Lab + Farmacia + Membresías (price_cents altas)', $this->deltaFloat((float) $gmv, (float) $gmvPrev)),
            $this->metricCard('neto', 'Ingreso neto', $this->money($net), 'proxy', 'lime', 'GMV − descuentos en compra (sin COGS/comisiones)', $this->deltaFloat((float) $net, (float) $netPrev)),
            $this->metricCard('pedidos', 'Pedidos', number_format($ordersAll), 'disponible', 'sky', 'Órdenes lab/farmacia + altas membresía titulares', $this->deltaFloat((float) $ordersAll, (float) $ordersAllPrev)),
            $this->metricCard('ticket', 'Ticket promedio', $this->money($ticket), 'disponible', 'sky', 'GMV lab+farmacia / pedidos producto', $this->deltaFloat((float) $ticket, (float) $ticketPrev)),
            $this->metricCard('carritos', 'Carritos', number_format($carts['created']), 'disponible', 'sky', 'Carts creados en el periodo (lab+pharmacy)', $this->deltaFloat((float) $carts['created'], (float) $cartsPrev['created'])),
            $this->metricCard(
                'conversion',
                'Conversión',
                $carts['conversion'] === null ? '—' : $carts['conversion'].'%',
                'disponible',
                'sky',
                'Completados / (completados + abandonados 30m)',
                $carts['conversion'] !== null && $cartsPrev['conversion'] !== null
                    ? $this->deltaFloat($carts['conversion'], $cartsPrev['conversion'])
                    : null,
            ),
            $this->metricCard(
                'abandono',
                'Abandono',
                number_format($carts['abandoned']).' ($'.number_format($carts['abandoned_value'], 2).')',
                'disponible',
                'amber',
                'Carritos active stale > 30 min',
                $this->deltaFloat((float) $carts['abandoned'], (float) $cartsPrev['abandoned'], true),
            ),
            $this->metricCard(
                'descuentos',
                'Descuentos',
                $this->money($discountDisplay),
                'disponible',
                'amber',
                'Máx(ledger CouponTransaction, columnas coupon/discount en compras)',
                $this->deltaFloat((float) $discountDisplay, (float) max($discountsPrev['amount_cents'], $channelDiscountsPrev), true),
            ),
        ];

        $business = [
            $this->kpi('gmv', 'GMV', $gmv / 100, $gmvPrev / 100, 'green', 'disponible', 'MXN', true),
            $this->kpi('pedidos', 'Pedidos', $ordersAll, $ordersAllPrev, 'blue', 'disponible', 'conteo', false),
            $this->kpi('ticket', 'Ticket', $ticket / 100, $ticketPrev / 100, 'blue', 'disponible', 'MXN', true),
            $this->kpi('conversion', 'Conversión %', (float) ($carts['conversion'] ?? 0), (float) ($cartsPrev['conversion'] ?? 0), 'orange', 'disponible', '%', false),
        ];

        return ['cards' => $cards, 'business' => $business];
    }

    /**
     * @param  array<string, array{orders: int, gmv_cents: int, discount_cents: int}>  $channels
     * @return list<array<string, mixed>>
     */
    private function buildDistribution(array $channels): array
    {
        $totalGmv = max(1, $channels['lab']['gmv_cents'] + $channels['pharmacy']['gmv_cents'] + $channels['membership']['gmv_cents']);

        $map = [
            'laboratories' => ['label' => 'Laboratorios', 'key' => 'lab', 'href' => 'admin.activecampaign.laboratories'],
            'pharmacy' => ['label' => 'Farmacia', 'key' => 'pharmacy', 'href' => null],
            'memberships' => ['label' => 'Membresías', 'key' => 'membership', 'href' => 'admin.activecampaign.memberships'],
        ];

        $rows = [];
        foreach ($map as $id => $meta) {
            $ch = $channels[$meta['key']];
            $rows[] = [
                'id' => $id,
                'label' => $meta['label'],
                'orders' => $ch['orders'],
                'orders_label' => number_format($ch['orders']),
                'gmv_label' => $this->money($ch['gmv_cents']),
                'gmv_cents' => $ch['gmv_cents'],
                'share_percent' => round(100 * $ch['gmv_cents'] / $totalGmv, 1),
                'truth' => 'disponible',
                'href' => $meta['href'] ? route($meta['href']) : null,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildPaymentMethods(ActiveCampaignDashboardFilter $filter): array
    {
        $startS = $filter->start->toDateTimeString();
        $endS = $filter->end->toDateTimeString();
        $labExpr = $this->labActivityExpr('lp');

        $lab = DB::table('laboratory_purchases as lp')
            ->join('transactionables as t', function ($join) {
                $join->on('t.transactionable_id', '=', 'lp.id')
                    ->where('t.transactionable_type', LaboratoryPurchase::class);
            })
            ->join('transactions as tr', 'tr.id', '=', 't.transaction_id')
            ->whereNull('lp.deleted_at')
            ->whereRaw("{$labExpr} BETWEEN ? AND ?", [$startS, $endS])
            ->selectRaw("tr.payment_method, COUNT(DISTINCT lp.id) as orders, 'lab' as channel")
            ->groupBy('tr.payment_method');

        $pharmacy = DB::table('online_pharmacy_purchases as opp')
            ->join('transactionables as t', function ($join) {
                $join->on('t.transactionable_id', '=', 'opp.id')
                    ->where('t.transactionable_type', OnlinePharmacyPurchase::class);
            })
            ->join('transactions as tr', 'tr.id', '=', 't.transaction_id')
            ->whereNull('opp.deleted_at')
            ->whereBetween('opp.created_at', [$startS, $endS])
            ->selectRaw("tr.payment_method, COUNT(DISTINCT opp.id) as orders, 'pharmacy' as channel")
            ->groupBy('tr.payment_method');

        $membership = DB::table('medical_attention_subscriptions as mas')
            ->join('transactionables as t', function ($join) {
                $join->on('t.transactionable_id', '=', 'mas.id')
                    ->where('t.transactionable_type', MedicalAttentionSubscription::class);
            })
            ->join('transactions as tr', 'tr.id', '=', 't.transaction_id')
            ->whereNull('mas.deleted_at')
            ->where('mas.type', '!=', MedicalSubscriptionType::FAMILY_MEMBER->value)
            ->whereBetween('mas.created_at', [$startS, $endS])
            ->selectRaw("tr.payment_method, COUNT(DISTINCT mas.id) as orders, 'membership' as channel")
            ->groupBy('tr.payment_method');

        $union = $lab->unionAll($pharmacy)->unionAll($membership);

        return DB::query()
            ->fromSub($union, 'pm')
            ->selectRaw('payment_method, SUM(orders) as orders')
            ->groupBy('payment_method')
            ->orderByDesc('orders')
            ->get()
            ->map(fn ($row) => [
                'id' => (string) ($row->payment_method ?: 'unknown'),
                'label' => $row->payment_method ? ucfirst((string) $row->payment_method) : 'Sin método',
                'orders' => (int) $row->orders,
                'orders_label' => number_format((int) $row->orders),
                'truth' => 'disponible',
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildTopProducts(ActiveCampaignDashboardFilter $filter): array
    {
        $startS = $filter->start->toDateTimeString();
        $endS = $filter->end->toDateTimeString();
        $labExpr = $this->labActivityExpr('lp');

        $lab = DB::table('laboratory_purchase_items as lpi')
            ->join('laboratory_purchases as lp', 'lp.id', '=', 'lpi.laboratory_purchase_id')
            ->whereNull('lpi.deleted_at')
            ->whereNull('lp.deleted_at')
            ->whereRaw("{$labExpr} BETWEEN ? AND ?", [$startS, $endS])
            ->selectRaw("'lab' as channel, lpi.name as product_name, COUNT(*) as quantity, COALESCE(SUM(lpi.price_cents), 0) as revenue_cents")
            ->groupBy('lpi.name')
            ->orderByDesc('revenue_cents')
            ->limit(8)
            ->get();

        $pharmacy = DB::table('online_pharmacy_purchase_items as opi')
            ->join('online_pharmacy_purchases as opp', 'opp.id', '=', 'opi.online_pharmacy_purchase_id')
            ->whereNull('opp.deleted_at')
            ->whereBetween('opp.created_at', [$startS, $endS])
            ->selectRaw("'pharmacy' as channel, opi.name as product_name, SUM(opi.quantity) as quantity, COALESCE(SUM(opi.total_cents), 0) as revenue_cents")
            ->groupBy('opi.name')
            ->orderByDesc('revenue_cents')
            ->limit(8)
            ->get();

        return $lab->concat($pharmacy)
            ->sortByDesc('revenue_cents')
            ->take(12)
            ->values()
            ->map(fn ($row) => [
                'id' => ($row->channel.'-'.md5((string) $row->product_name)),
                'name' => (string) $row->product_name,
                'channel' => (string) $row->channel,
                'channel_label' => $row->channel === 'lab' ? 'Laboratorios' : 'Farmacia',
                'quantity' => (int) $row->quantity,
                'quantity_label' => number_format((int) $row->quantity),
                'revenue_label' => $this->money((int) $row->revenue_cents),
                'truth' => 'disponible',
            ])
            ->all();
    }

    /**
     * @return array{summary: list<array<string, mixed>>, top: list<array<string, mixed>>}
     */
    private function buildCoupons(ActiveCampaignDashboardFilter $filter): array
    {
        $startS = $filter->start->toDateTimeString();
        $endS = $filter->end->toDateTimeString();

        $byType = CouponTransaction::query()
            ->notReversed()
            ->whereBetween('created_at', [$startS, $endS])
            ->toBase()
            ->selectRaw('purchase_type')
            ->selectRaw('COUNT(*) as uses')
            ->selectRaw('COALESCE(SUM(amount_used_cents), 0) as amount_cents')
            ->groupBy('purchase_type')
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->purchase_type,
                'label' => match ((string) $row->purchase_type) {
                    'lab' => 'Laboratorios',
                    'pharmacy' => 'Farmacia',
                    default => (string) $row->purchase_type,
                },
                'uses' => (int) $row->uses,
                'uses_label' => number_format((int) $row->uses),
                'amount_label' => $this->money((int) $row->amount_cents),
                'truth' => 'disponible',
            ])
            ->values()
            ->all();

        $top = DB::table('coupon_transactions as ct')
            ->leftJoin('coupons as c', 'c.id', '=', 'ct.coupon_id')
            ->whereNull('ct.reversed_at')
            ->whereBetween('ct.created_at', [$startS, $endS])
            ->selectRaw('ct.coupon_id, COALESCE(c.code, CONCAT("#", ct.coupon_id)) as code')
            ->selectRaw('COUNT(*) as uses')
            ->selectRaw('COALESCE(SUM(ct.amount_used_cents), 0) as amount_cents')
            ->groupBy('ct.coupon_id', 'c.code')
            ->orderByDesc('amount_cents')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->coupon_id,
                'code' => (string) $row->code,
                'uses' => (int) $row->uses,
                'uses_label' => number_format((int) $row->uses),
                'amount_label' => $this->money((int) $row->amount_cents),
                'truth' => 'disponible',
            ])
            ->all();

        return [
            'summary' => $byType,
            'top' => $top,
            'note' => 'Ledger CouponTransaction (créditos Famedic). Promo codes / Vitau discount son capa aparte.',
        ];
    }

    /**
     * @return list<array{label: string, gmv: float, pedidos: int}>
     */
    private function seriesByDay(ActiveCampaignDashboardFilter $filter): array
    {
        return $this->mergeDailySeries($filter);
    }

    /**
     * @return list<array{label: string, gmv: float, pedidos: int}>
     */
    private function seriesByWeek(ActiveCampaignDashboardFilter $filter): array
    {
        return $this->bucketMerged($filter, '%x-W%v');
    }

    /**
     * @return list<array{label: string, gmv: float, pedidos: int}>
     */
    private function seriesByMonth(ActiveCampaignDashboardFilter $filter): array
    {
        return $this->bucketMerged($filter, '%Y-%m');
    }

    /**
     * @return list<array{label: string, gmv: float, pedidos: int}>
     */
    private function seriesByChannel(ActiveCampaignDashboardFilter $filter): array
    {
        $channels = $this->channelStats($filter->start, $filter->end);

        return [
            [
                'label' => 'Laboratorios',
                'gmv' => round($channels['lab']['gmv_cents'] / 100, 2),
                'pedidos' => $channels['lab']['orders'],
            ],
            [
                'label' => 'Farmacia',
                'gmv' => round($channels['pharmacy']['gmv_cents'] / 100, 2),
                'pedidos' => $channels['pharmacy']['orders'],
            ],
            [
                'label' => 'Membresías',
                'gmv' => round($channels['membership']['gmv_cents'] / 100, 2),
                'pedidos' => $channels['membership']['orders'],
            ],
        ];
    }

    /**
     * @return list<array{label: string, gmv: float, pedidos: int}>
     */
    private function mergeDailySeries(ActiveCampaignDashboardFilter $filter): array
    {
        $startS = $filter->start->toDateTimeString();
        $endS = $filter->end->toDateTimeString();
        $labExpr = $this->labActivityExpr();

        $lab = LaboratoryPurchase::query()->toBase()
            ->whereNull('deleted_at')
            ->whereRaw("{$labExpr} BETWEEN ? AND ?", [$startS, $endS])
            ->selectRaw("DATE({$labExpr}) as day_key")
            ->selectRaw('COUNT(*) as pedidos')
            ->selectRaw('COALESCE(SUM(total_cents), 0) as gmv_cents')
            ->groupBy('day_key');

        $pharmacy = OnlinePharmacyPurchase::query()->toBase()
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$startS, $endS])
            ->selectRaw('DATE(created_at) as day_key')
            ->selectRaw('COUNT(*) as pedidos')
            ->selectRaw('COALESCE(SUM(total_cents), 0) as gmv_cents')
            ->groupBy('day_key');

        $membership = MedicalAttentionSubscription::query()->toBase()
            ->whereNull('deleted_at')
            ->where('type', '!=', MedicalSubscriptionType::FAMILY_MEMBER->value)
            ->whereBetween('created_at', [$startS, $endS])
            ->selectRaw('DATE(created_at) as day_key')
            ->selectRaw('COUNT(*) as pedidos')
            ->selectRaw('COALESCE(SUM(price_cents), 0) as gmv_cents')
            ->groupBy('day_key');

        $union = $lab->unionAll($pharmacy)->unionAll($membership);

        return DB::query()
            ->fromSub($union, 'd')
            ->selectRaw('day_key, SUM(pedidos) as pedidos, SUM(gmv_cents) as gmv_cents')
            ->groupBy('day_key')
            ->orderBy('day_key')
            ->get()
            ->map(function ($row) {
                $day = Carbon::parse((string) $row->day_key, self::TZ);

                return [
                    'label' => $day->format('d/m'),
                    'pedidos' => (int) $row->pedidos,
                    'gmv' => round(((int) $row->gmv_cents) / 100, 2),
                ];
            })
            ->all();
    }

    /**
     * @return list<array{label: string, gmv: float, pedidos: int}>
     */
    private function bucketMerged(ActiveCampaignDashboardFilter $filter, string $format): array
    {
        $startS = $filter->start->toDateTimeString();
        $endS = $filter->end->toDateTimeString();
        $labExpr = $this->labActivityExpr();

        $lab = LaboratoryPurchase::query()->toBase()
            ->whereNull('deleted_at')
            ->whereRaw("{$labExpr} BETWEEN ? AND ?", [$startS, $endS])
            ->selectRaw("DATE_FORMAT({$labExpr}, '{$format}') as bucket")
            ->selectRaw('COUNT(*) as pedidos')
            ->selectRaw('COALESCE(SUM(total_cents), 0) as gmv_cents')
            ->groupBy('bucket');

        $pharmacy = OnlinePharmacyPurchase::query()->toBase()
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$startS, $endS])
            ->selectRaw("DATE_FORMAT(created_at, '{$format}') as bucket")
            ->selectRaw('COUNT(*) as pedidos')
            ->selectRaw('COALESCE(SUM(total_cents), 0) as gmv_cents')
            ->groupBy('bucket');

        $membership = MedicalAttentionSubscription::query()->toBase()
            ->whereNull('deleted_at')
            ->where('type', '!=', MedicalSubscriptionType::FAMILY_MEMBER->value)
            ->whereBetween('created_at', [$startS, $endS])
            ->selectRaw("DATE_FORMAT(created_at, '{$format}') as bucket")
            ->selectRaw('COUNT(*) as pedidos')
            ->selectRaw('COALESCE(SUM(price_cents), 0) as gmv_cents')
            ->groupBy('bucket');

        $union = $lab->unionAll($pharmacy)->unionAll($membership);

        return DB::query()
            ->fromSub($union, 'b')
            ->selectRaw('bucket, SUM(pedidos) as pedidos, SUM(gmv_cents) as gmv_cents')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->map(fn ($row) => [
                'label' => (string) $row->bucket,
                'pedidos' => (int) $row->pedidos,
                'gmv' => round(((int) $row->gmv_cents) / 100, 2),
            ])
            ->all();
    }

    /**
     * @param  array{cards: list<array<string, mixed>>}  $kpis
     * @param  list<array<string, mixed>>  $distribution
     * @param  array{created: int, completed: int, abandoned: int, abandoned_value: float, conversion: float|null}  $carts
     * @param  array<string, mixed>  $overview
     * @return array{insights: list<array<string, mixed>>, recommendations: list<array<string, mixed>>, risks: list<array<string, mixed>>}
     */
    private function buildDecision(
        ActiveCampaignDashboardFilter $filter,
        array $kpis,
        array $distribution,
        array $carts,
        array $overview,
    ): array {
        $analytics = $this->analytics->build($filter);
        $businessDomain = collect($analytics['domains'] ?? [])->firstWhere('id', 'business') ?? [];

        $insights = [
            $this->item(
                'Ecommerce Intelligence consolida GMV y pedidos de Lab, Farmacia y Membresías en una sola vista de Dirección.',
                'disponible',
            ),
        ];

        $leader = collect($distribution)->sortByDesc('gmv_cents')->first();
        if ($leader) {
            $insights[] = $this->item(
                "Línea dominante del periodo: {$leader['label']} ({$leader['gmv_label']}, {$leader['share_percent']}% del GMV).",
                'disponible',
            );
        }

        foreach ($businessDomain['insights'] ?? [] as $item) {
            $insights[] = $item;
        }

        $recommendations = [
            $this->item(
                'Profundizar canal en Laboratory Intelligence o Membership Intelligence según la línea dominante.',
                'disponible',
            ),
            $this->item(
                'Si el abandono es alto, cruzar con Automation Center (carritos abandonados) y Funnel Lab/Farmacia.',
                'disponible',
            ),
        ];
        foreach ($businessDomain['recommendations'] ?? [] as $item) {
            $recommendations[] = $item;
        }

        $risks = [];
        if ($carts['abandoned'] > 0) {
            $risks[] = $this->item(
                "{$carts['abandoned']} carritos abandonados (~\$".number_format($carts['abandoned_value'], 2).') en el periodo.',
                'disponible',
            );
        }
        if ($carts['conversion'] !== null && $carts['conversion'] < 40) {
            $risks[] = $this->item(
                "Conversión carrito→compra en {$carts['conversion']}% (umbral interno de atención < 40%).",
                'disponible',
            );
        }
        foreach ($businessDomain['risks'] ?? [] as $item) {
            $risks[] = $item;
        }

        $dashLab = collect($overview['business'] ?? [])->firstWhere('id', 'lab');
        $dashPh = collect($overview['business'] ?? [])->firstWhere('id', 'pharmacy');
        if ($dashLab || $dashPh) {
            $insights[] = $this->item(
                'Señal cruzada Dashboard: Lab '.($dashLab['value_formatted'] ?? '—').' · Farmacia '.($dashPh['value_formatted'] ?? '—').' (proxies de conteo).',
                'proxy',
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
    private function suggestedActions(): array
    {
        return [
            [
                'id' => 'lab',
                'label' => 'Laboratory Intelligence',
                'href' => route('admin.activecampaign.laboratories'),
                'enabled' => true,
            ],
            [
                'id' => 'membership',
                'label' => 'Membership Intelligence',
                'href' => route('admin.activecampaign.memberships'),
                'enabled' => true,
            ],
            [
                'id' => 'funnels',
                'label' => 'Funnels Intelligence',
                'href' => route('admin.activecampaign.funnels'),
                'enabled' => true,
            ],
            [
                'id' => 'analytics',
                'label' => 'Analytics',
                'href' => route('admin.activecampaign.analytics'),
                'enabled' => true,
            ],
            [
                'id' => 'dashboard',
                'label' => 'Dashboard',
                'href' => route('admin.activecampaign.dashboard'),
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
    private function gaps(): array
    {
        return [
            [
                'label' => 'COGS / margen real',
                'reason' => 'Ingreso neto actual solo resta descuentos; no incluye costo ni comisiones.',
                'truth' => 'instrumentacion',
            ],
            [
                'label' => 'Atribución marketing / campañas',
                'reason' => 'GA/Meta no conectados; dispatches AC ≠ atribución de venta.',
                'truth' => 'instrumentacion',
            ],
            [
                'label' => 'paid_at farmacia',
                'reason' => 'Pharmacy usa created_at; paid_at no está migrado de forma consistente.',
                'truth' => 'proxy',
            ],
            [
                'label' => 'Promo codes vs créditos cupón',
                'reason' => 'Esta consola prioriza CouponTransaction; redenciones promo son capa aparte.',
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
        bool $money,
    ): array {
        $delta = $this->deltaFloat((float) $current, (float) $previous);

        return [
            'id' => $id,
            'label' => $label,
            'value_formatted' => $money
                ? '$'.number_format((float) $current, 2)
                : number_format((float) $current, $id === 'conversion' ? 1 : 0),
            'previous_formatted' => $money
                ? '$'.number_format((float) $previous, 2)
                : number_format((float) $previous, $id === 'conversion' ? 1 : 0),
            'hint' => $hint,
            'tone' => $tone,
            'truth' => $truth,
            'source' => 'ecommerce_consolidation',
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
                return ['percent' => 0.0, 'direction' => 'flat', 'is_positive' => null, 'label' => '0%'];
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
        $isPositive = $direction === 'flat' ? null : (($direction === 'up') xor $higherIsWorse);
        $label = $direction === 'flat' ? '0%' : (($direction === 'down' ? '−' : '+').$pct.'%');

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
