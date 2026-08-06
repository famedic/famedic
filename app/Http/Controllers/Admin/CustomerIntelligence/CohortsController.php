<?php

namespace App\Http\Controllers\Admin\CustomerIntelligence;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CustomerIntelligence\IndexCohortsRequest;
use App\Http\Resources\Admin\CustomerIntelligence\CohortsResource;
use App\Services\CustomerIntelligence\CohortsAnalyticsService;
use App\Support\CustomerIntelligence\CohortsFilter;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class CohortsController extends Controller
{
    public function __construct(
        private CohortsAnalyticsService $analytics,
    ) {
    }

    public function index(IndexCohortsRequest $request): Response
    {
        $filter = CohortsFilter::fromRequest($request);
        $dashboard = $this->analytics->build($filter);

        return Inertia::render('Admin/CustomerIntelligence/Cohorts', [
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
                'genders' => [
                    ['value' => '1', 'label' => 'Masculino'],
                    ['value' => '2', 'label' => 'Femenino'],
                ],
            ],
            'kpis' => $dashboard['kpis'],
            'heatmap' => $dashboard['heatmap'],
            'curves' => $dashboard['curves'],
            'sourceComparison' => $dashboard['source_comparison'],
            'repeatLadder' => $dashboard['repeat_ladder'],
            'daysBetween' => $dashboard['days_between'],
            'churn' => $dashboard['churn'],
            'ltv' => $dashboard['ltv'],
            'segments' => $dashboard['segments'],
            'aiInsights' => $dashboard['ai_insights'],
            'automations' => $dashboard['automations'],
            'meta' => $dashboard['meta'],
            'journeyUrl' => route('admin.customer-intelligence.customer-journey'),
            'dormantUrl' => route('admin.customers.dormant'),
            'customersIndexUrl' => route('admin.customers.index'),
        ]);
    }

    public function data(IndexCohortsRequest $request): JsonResponse
    {
        $filter = CohortsFilter::fromRequest($request);
        $dashboard = $this->analytics->build($filter);

        return response()->json([
            'filters' => $filter->toArray(),
            'dashboard' => new CohortsResource($dashboard),
            'meta' => [
                'generated_at' => $dashboard['meta']['generated_at'] ?? null,
            ],
        ]);
    }
}
