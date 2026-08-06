<?php

namespace App\Http\Controllers\Admin\CustomerIntelligence;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CustomerIntelligence\IndexCustomerHealthRequest;
use App\Http\Resources\Admin\CustomerIntelligence\CustomerHealthResource;
use App\Models\Customer;
use App\Services\CustomerIntelligence\CustomerHealthAnalyticsService;
use App\Services\CustomerIntelligence\CustomerHealthRepository;
use App\Support\CustomerIntelligence\CustomerHealthFilter;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class CustomerHealthController extends Controller
{
    public function __construct(
        private CustomerHealthAnalyticsService $analytics,
        private CustomerHealthRepository $repository,
    ) {
    }

    public function index(IndexCustomerHealthRequest $request): Response
    {
        $filter = CustomerHealthFilter::fromRequest($request);

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
            return Inertia::render('Admin/CustomerIntelligence/CustomerHealth', [
                'drawer' => $drawer,
            ]);
        }

        $dashboard = $this->analytics->build($filter);

        return Inertia::render('Admin/CustomerIntelligence/CustomerHealth', [
            'filters' => $filter->toArray(),
            'filterOptions' => [
                'account_types' => [
                    ['value' => 'regular', 'label' => 'Regular'],
                    ['value' => 'odessa', 'label' => 'Odessa'],
                    ['value' => 'familiar', 'label' => 'Familiar'],
                ],
                'sources' => [
                    ['value' => 'organico', 'label' => 'Orgánico'],
                    ['value' => 'referred', 'label' => 'Referidos'],
                    ['value' => 'odessa', 'label' => 'Odessa'],
                    ['value' => 'familiar', 'label' => 'Familiar'],
                ],
                'states' => $this->analytics->stateOptions(),
                'cities' => $this->analytics->cityOptions(),
                'health_bands' => [
                    ['value' => 'excellent', 'label' => 'Excelente'],
                    ['value' => 'good', 'label' => 'Bueno'],
                    ['value' => 'at_risk', 'label' => 'En Riesgo'],
                    ['value' => 'critical', 'label' => 'Crítico'],
                    ['value' => 'lost', 'label' => 'Perdido'],
                ],
                'segments' => [
                    ['value' => 'premium', 'label' => 'Premium'],
                    ['value' => 'vip', 'label' => 'VIP'],
                    ['value' => 'high_value', 'label' => 'Alto Valor'],
                    ['value' => 'dormant', 'label' => 'Dormidos'],
                    ['value' => 'recoverable', 'label' => 'Recuperables'],
                    ['value' => 'lost', 'label' => 'Perdidos'],
                    ['value' => 'high_risk', 'label' => 'Alto Riesgo'],
                    ['value' => 'next_purchase', 'label' => 'Próxima Compra'],
                    ['value' => 'high_conversion', 'label' => 'Alta Conversión'],
                ],
                'sorts' => [
                    ['value' => 'health_desc', 'label' => 'Health ↓'],
                    ['value' => 'health_asc', 'label' => 'Health ↑'],
                    ['value' => 'ltv_desc', 'label' => 'LTV ↓'],
                    ['value' => 'churn_desc', 'label' => 'Churn ↓'],
                    ['value' => 'recent', 'label' => 'Más recientes'],
                ],
            ],
            'kpis' => $dashboard['kpis'],
            'gauge' => $dashboard['gauge'],
            'histogram' => $dashboard['histogram'],
            'scatter' => $dashboard['scatter'],
            'byCity' => $dashboard['by_city'],
            'bySource' => $dashboard['by_source'],
            'byChannel' => $dashboard['by_channel'],
            'bands' => $dashboard['bands'],
            'segments' => $dashboard['segments'],
            'predictiveAverages' => $dashboard['predictive_averages'],
            'recommendations' => $dashboard['recommendations'],
            'aiInsights' => $dashboard['ai_insights'],
            'automations' => $dashboard['automations'],
            'customers' => $dashboard['customers'],
            'drawer' => $drawer,
            'meta' => $dashboard['meta'],
            'journeyUrl' => route('admin.customer-intelligence.customer-journey'),
            'cohortsUrl' => route('admin.customer-intelligence.cohorts'),
            'dormantUrl' => route('admin.customers.dormant'),
            'customersIndexUrl' => route('admin.customers.index'),
        ]);
    }

    public function data(IndexCustomerHealthRequest $request): JsonResponse
    {
        $filter = CustomerHealthFilter::fromRequest($request);
        $dashboard = $this->analytics->build($filter);

        return response()->json([
            'filters' => $filter->toArray(),
            'dashboard' => new CustomerHealthResource(collect($dashboard)->except('customers')->all()),
            'customers' => CustomerHealthResource::collection(collect($dashboard['customers']->items())),
            'meta' => [
                'generated_at' => $dashboard['meta']['generated_at'] ?? null,
                'sample_size' => $dashboard['meta']['sample_size'] ?? null,
            ],
        ]);
    }
}
