<?php

namespace App\Http\Controllers\Admin\CustomerIntelligence;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CustomerIntelligence\IndexCustomerJourneyRequest;
use App\Http\Resources\Admin\CustomerIntelligence\CustomerJourneyResource;
use App\Models\Customer;
use App\Services\CustomerIntelligence\CustomerJourneyAnalyticsService;
use App\Services\CustomerIntelligence\CustomerJourneyRepository;
use App\Support\CustomerIntelligence\CustomerJourneyFilter;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class CustomerJourneyController extends Controller
{
    public function __construct(
        private CustomerJourneyAnalyticsService $analytics,
        private CustomerJourneyRepository $repository,
    ) {
    }

    public function index(IndexCustomerJourneyRequest $request): Response
    {
        $filter = CustomerJourneyFilter::fromRequest($request);

        $only = collect(explode(',', (string) $request->header('X-Inertia-Partial-Data')))
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->values();

        $drawerOnly = $only->count() === 1 && $only->first() === 'drawer';

        $drawer = null;
        if ($request->filled('drawer_customer_id')) {
            $customer = Customer::query()->find($request->integer('drawer_customer_id'));
            if ($customer) {
                $drawer = $this->repository->customerDrawer($customer);
            }
        }

        if ($drawerOnly) {
            return Inertia::render('Admin/CustomerIntelligence/CustomerJourney', [
                'drawer' => $drawer,
            ]);
        }

        $dashboard = $this->analytics->build($filter);
        $users = $this->repository->paginateUsers($filter);

        return Inertia::render('Admin/CustomerIntelligence/CustomerJourney', [
            'filters' => $filter->toArray(),
            'filterOptions' => [
                'account_types' => [
                    ['value' => 'regular', 'label' => 'Regular'],
                    ['value' => 'odessa', 'label' => 'Odessa'],
                    ['value' => 'familiar', 'label' => 'Familiar'],
                ],
                'compare_modes' => [
                    ['value' => 'period', 'label' => 'Periodo vs anterior'],
                    ['value' => 'month_vs_previous', 'label' => 'Este mes vs mes pasado'],
                    ['value' => '30_vs_90', 'label' => 'Últimos 30 vs 90 días'],
                ],
                'heatmap_metrics' => [
                    ['value' => 'registrations', 'label' => 'Registros'],
                    ['value' => 'logins', 'label' => 'Logins (proxy)'],
                    ['value' => 'checkouts', 'label' => 'Checkouts'],
                    ['value' => 'purchases', 'label' => 'Compras'],
                ],
            ],
            'kpis' => $dashboard['kpis'],
            'stages' => $dashboard['stages'],
            'previousStages' => $dashboard['previous_stages'],
            'funnel' => $dashboard['funnel'],
            'sankey' => $dashboard['sankey'],
            'timeline' => $dashboard['timeline'],
            'heatmap' => $dashboard['heatmap'],
            'paths' => $dashboard['paths'],
            'marketingInsights' => $dashboard['marketing_insights'],
            'aiInsights' => $dashboard['ai_insights'],
            'automations' => $dashboard['automations'],
            'predictive' => $dashboard['predictive'],
            'compare' => $dashboard['compare'],
            'users' => $users,
            'drawer' => $drawer,
            'meta' => $dashboard['meta'],
            'dormantUrl' => route('admin.customers.dormant'),
            'customersIndexUrl' => route('admin.customers.index'),
        ]);
    }

    /**
     * Endpoint JSON para integraciones futuras (GA4, ActiveCampaign, IA).
     */
    public function data(IndexCustomerJourneyRequest $request): JsonResponse
    {
        $filter = CustomerJourneyFilter::fromRequest($request);
        $dashboard = $this->analytics->build($filter);
        $users = $this->repository->paginateUsers($filter, min(50, $request->integer('per_page') ?: 20));

        return response()->json([
            'filters' => $filter->toArray(),
            'dashboard' => $dashboard,
            'users' => CustomerJourneyResource::collection(collect($users->items())),
            'meta' => [
                'pagination' => [
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'total' => $users->total(),
                ],
                'generated_at' => $dashboard['meta']['generated_at'] ?? null,
            ],
        ]);
    }
}
