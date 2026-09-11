<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CartsDashboard\CartsAnalyticsService;
use App\Support\CartsDashboard\CartsDashboardFilter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CartsDashboardController extends Controller
{
    public function __construct(
        private CartsAnalyticsService $analytics,
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
                'types' => [
                    ['value' => 'lab', 'label' => 'Laboratorio'],
                    ['value' => 'pharmacy', 'label' => 'Farmacia'],
                ],
                'periods' => [
                    ['value' => 'today', 'label' => 'Hoy'],
                    ['value' => 'last_7_days', 'label' => 'Ultimos 7 dias'],
                    ['value' => 'last_30_days', 'label' => 'Ultimos 30 dias'],
                    ['value' => 'this_month', 'label' => 'Este mes'],
                    ['value' => 'custom', 'label' => 'Rango personalizado'],
                ],
            ],
            'kpis' => $dashboard['kpis'],
            'operationalKpis' => $dashboard['operational_kpis'],
            'daily' => $dashboard['daily'],
            'funnel' => $dashboard['funnel'],
            'payments' => $dashboard['payments'],
            'appointments' => $dashboard['appointments'],
            'contact' => $dashboard['contact'],
            'laboratories' => $dashboard['laboratories'],
            'laboratoryCharts' => $dashboard['laboratory_charts'],
            'customerProfile' => $dashboard['customer_profile'],
            'ticketAverages' => $dashboard['ticket_averages'],
            'topStudies' => $dashboard['top_studies'],
            'meta' => $dashboard['meta'],
            'cartsIndexUrl' => route('admin.carts.index'),
        ]);
    }
}
