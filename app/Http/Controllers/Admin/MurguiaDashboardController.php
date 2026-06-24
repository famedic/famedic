<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Murguia\MurguiaDashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MurguiaDashboardController extends Controller
{
    public function index(Request $request, MurguiaDashboardService $dashboardService): Response
    {
        $filters = $request->only([
            'search',
            'account_type',
            'local_status',
            'subscription_type',
            'murguia_sync',
            'has_certificate_account',
            'has_family_dependents',
            'created_from',
            'created_to',
            'expires_from',
            'expires_to',
            'sync_from',
            'sync_to',
            'payment_from',
            'payment_to',
        ]);

        $dashboard = $dashboardService->getDashboardData($filters);

        return Inertia::render('Admin/Murguia/Dashboard', [
            'filters' => $filters,
            'summary' => $dashboard['summary'],
            'charts' => $dashboard['charts'],
        ]);
    }
}
