<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\LaboratoryPurchases\BuildLaboratoryPurchaseChartsDataAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryPurchases\IndexLaboratoryPurchaseRequest;
use Carbon\Carbon;
use Inertia\Inertia;

class LaboratoryPurchaseChartController extends Controller
{
    public function index(
        IndexLaboratoryPurchaseRequest $request,
        BuildLaboratoryPurchaseChartsDataAction $buildLaboratoryPurchaseChartsDataAction
    ) {
        $filters = collect($request->only([
            'search',
            'deleted',
            'start_date',
            'end_date',
            'invoice_requested',
            'invoice_uploaded',
            'results_uploaded',
            'payment_method',
            'payment_status',
            'brand',
            'dev_assistance',
        ]))->filter()->all();

        if (empty($filters['start_date']) && empty($filters['end_date'])) {
            $filters['start_date'] = Carbon::now('America/Monterrey')->subDays(30)->startOfDay()->toDateString();
            $filters['end_date'] = Carbon::now('America/Monterrey')->endOfDay()->toDateString();
        }

        $charts = $buildLaboratoryPurchaseChartsDataAction(
            $filters,
            $request->start_date ? Carbon::parse($request->start_date, 'America/Monterrey') : null,
            $request->end_date ? Carbon::parse($request->end_date, 'America/Monterrey') : null
        );

        if (! empty($filters['start_date'])) {
            $filters['formatted_start_date'] = Carbon::parse($filters['start_date'], 'America/Monterrey')->isoFormat('MMM D, Y');
        }

        if (! empty($filters['end_date'])) {
            $filters['formatted_end_date'] = Carbon::parse($filters['end_date'], 'America/Monterrey')->isoFormat('MMM D, Y');
        }

        return Inertia::render('Admin/LaboratoryPurchasesChart', [
            'charts' => $charts,
            'filters' => $filters,
        ]);
    }
}
