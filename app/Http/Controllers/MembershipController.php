<?php

namespace App\Http\Controllers;

use App\Services\Membership\MembershipDashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MembershipController extends Controller
{
    public function index(Request $request, MembershipDashboardService $dashboardService)
    {
        $customer = $request->user()->customer;
        $customer->load([
            'user',
            'familyAccounts',
            'medicalAttentionSubscriptions.transactions',
        ]);

        return Inertia::render('Membership/Index', [
            'membership' => $dashboardService->build($customer),
        ]);
    }
}
