<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CartsDashboard\CartsAnalyticsService;
use App\Services\CartsDashboard\CartsDashboardRepository;
use App\Support\CartsDashboard\CartsDashboardFilter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CartsDashboardController extends Controller
{
    public function __construct(
        private CartsAnalyticsService $analytics,
        private CartsDashboardRepository $repository,
    ) {
    }

    public function index(Request $request): Response
    {
        $request->user()->administrator->hasPermissionTo('view carts') || abort(403);

        $filter = CartsDashboardFilter::fromRequest($request);
        $dashboard = $this->analytics->build($filter);

        return Inertia::render('Admin/Carts/Dashboard', [
            'filters' => $filter->toArray(),
            'filterOptions' => [
                'brands' => $this->analytics->brandOptions(),
                'cities' => $this->repository->availableCities(),
                'payment_methods' => [
                    ['value' => 'efevoopay', 'label' => 'Tarjeta (Efevoo)'],
                    ['value' => 'odessa', 'label' => 'Saldo a la Vista (Odessa)'],
                    ['value' => 'paypal', 'label' => 'PayPal'],
                    ['value' => 'coupon_balance', 'label' => 'Crédito a favor'],
                ],
            ],
            'kpis' => $dashboard['kpis'],
            'salesVsAbandoned' => $dashboard['sales_vs_abandoned'],
            'trends' => $dashboard['trends'],
            'laboratories' => $dashboard['laboratories'],
            'laboratoryCharts' => $dashboard['laboratory_charts'],
            'topStudies' => $dashboard['top_studies'],
            'revenueDistribution' => $dashboard['revenue_distribution'],
            'meta' => $dashboard['meta'],
            'cartsIndexUrl' => route('admin.carts.index'),
            'exportUrl' => route('admin.carts.export'),
        ]);
    }
}
