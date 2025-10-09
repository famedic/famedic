<?php

namespace App\Http\Controllers\Admin;

use App\Actions\BuildDailyChartDataAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OnlinePharmacyPurchases\IndexOnlinePharmacyPurchaseRequest;
use App\Http\Requests\Admin\OnlinePharmacyPurchases\ShowOnlinePharmacyPurchaseRequest;
use App\Models\OnlinePharmacyPurchase;
use Carbon\Carbon;
use Inertia\Inertia;

class OnlinePharmacyPurchaseController extends Controller
{
    public function index(IndexOnlinePharmacyPurchaseRequest $request, BuildDailyChartDataAction $buildDailyChartDataAction)
    {
        $filters = collect($request->only('search', 'deleted', 'start_date', 'end_date', 'payment_method', 'dev_assistance'))->filter()->all();

        $onlinePharmacyPurchasesQuery = OnlinePharmacyPurchase::with([
            'transactions',
            'vendorPayments',
            'onlinePharmacyPurchaseItems',
            'customer.user',
            'invoice',
            'invoiceRequest',
            'devAssistanceRequests',
        ])->filter($filters);

        $purchasesForChart = (clone $onlinePharmacyPurchasesQuery)->get();

        $onlinePharmacyDailyChart = $buildDailyChartDataAction(
            $purchasesForChart,
            $request->start_date ? Carbon::parse($request->start_date, 'America/Monterrey') : null,
            $request->end_date ? Carbon::parse($request->end_date, 'America/Monterrey') : null
        );

        $onlinePharmacyPurchases = $onlinePharmacyPurchasesQuery->latest()->paginate()->withQueryString();

        if (! empty($filters['start_date'])) {
            $filters['formatted_start_date'] = Carbon::parse($filters['start_date'], 'America/Monterrey')->isoFormat('MMM D, Y');
        }

        if (! empty($filters['end_date'])) {
            $filters['formatted_end_date'] = Carbon::parse($filters['end_date'], 'America/Monterrey')->isoFormat('MMM D, Y');
        }

        return Inertia::render('Admin/OnlinePharmacyPurchases', [
            'onlinePharmacyPurchases' => $onlinePharmacyPurchases,
            'chart' => $onlinePharmacyDailyChart,
            'filters' => $filters,
            'canExport' => $request->user()->administrator->hasPermissionTo('online-pharmacy-purchases.manage.export'),
        ]);
    }

    public function show(ShowOnlinePharmacyPurchaseRequest $request, OnlinePharmacyPurchase $onlinePharmacyPurchase)
    {
        return Inertia::render('Admin/OnlinePharmacyPurchase', [
            'onlinePharmacyPurchase' => $onlinePharmacyPurchase->load([
                'transactions',
                'vendorPayments',
                'onlinePharmacyPurchaseItems',
                'customer.user',
                'invoice',
                'invoiceRequest',
                'devAssistanceRequests.administrator.user',
                'devAssistanceRequests.comments.administrator.user',
            ]),
        ]);
    }
}
