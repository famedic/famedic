<?php

namespace App\Http\Controllers\Admin;

use App\Actions\BuildDailyChartDataAction;
use App\Actions\Laboratories\DeleteLaboratoryPurchaseAction;
use App\Enums\LaboratoryBrand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryPurchases\DestroyLaboratoryPurchaseRequest;
use App\Http\Requests\Admin\LaboratoryPurchases\IndexLaboratoryPurchaseRequest;
use App\Http\Requests\Admin\LaboratoryPurchases\ShowLaboratoryPurchaseRequest;
use App\Models\LaboratoryPurchase;
use Carbon\Carbon;
use Inertia\Inertia;

class LaboratoryPurchaseController extends Controller
{
    public function index(IndexLaboratoryPurchaseRequest $request, BuildDailyChartDataAction $buildDailyChartDataAction)
    {
        $filters = collect($request->only([
            'search',
            'deleted',
            'start_date',
            'end_date',
            'invoice_requested',
            'invoice_uploaded',
            'results_uploaded',
            'payment_method',
            'brand',
            'dev_assistance',
        ]))->filter()->all();

        $laboratoryPurchasesQuery = LaboratoryPurchase::with([
            'transactions',
            'vendorPayments',
            'laboratoryPurchaseItems',
            'customer.user',
            'invoice',
            'invoiceRequest',
            'devAssistanceRequests',
        ])->filter($filters);

        $purchasesForChart = (clone $laboratoryPurchasesQuery)->get();

        $laboratoryDailyChart = $buildDailyChartDataAction(
            $purchasesForChart,
            $request->start_date ? Carbon::parse($request->start_date, 'America/Monterrey') : null,
            $request->end_date ? Carbon::parse($request->end_date, 'America/Monterrey') : null
        );

        $laboratoryPurchases = $laboratoryPurchasesQuery->latest()->paginate()->withQueryString();

        if (! empty($filters['start_date'])) {
            $filters['formatted_start_date'] = Carbon::parse($filters['start_date'], 'America/Monterrey')->isoFormat('MMM D, Y');
        }

        if (! empty($filters['end_date'])) {
            $filters['formatted_end_date'] = Carbon::parse($filters['end_date'], 'America/Monterrey')->isoFormat('MMM D, Y');
        }

        return Inertia::render('Admin/LaboratoryPurchases', [
            'laboratoryPurchases' => $laboratoryPurchases,
            'chart' => $laboratoryDailyChart,
            'filters' => $filters,
            'brands' => LaboratoryBrand::brandsData(),
            'canExport' => $request->user()->administrator->hasPermissionTo('laboratory-purchases.manage.export'),
        ]);
    }

    public function show(ShowLaboratoryPurchaseRequest $request, LaboratoryPurchase $laboratoryPurchase)
    {
        return Inertia::render('Admin/LaboratoryPurchase', [
            'laboratoryPurchase' => $laboratoryPurchase->load([
                'transactions',
                'vendorPayments',
                'laboratoryPurchaseItems',
                'customer.user',
                'invoice',
                'invoiceRequest',
                'laboratoryAppointment.laboratoryStore',
                'devAssistanceRequests.administrator.user',
                'devAssistanceRequests.comments.administrator.user',
            ]),
            'showDeleteButton' => $request->user()->can('delete', $laboratoryPurchase),
        ]);
    }

    public function destroy(DestroyLaboratoryPurchaseRequest $request, LaboratoryPurchase $laboratoryPurchase, DeleteLaboratoryPurchaseAction $deleteLaboratoryPurchaseAction)
    {
        ($deleteLaboratoryPurchaseAction)($laboratoryPurchase);

        return redirect()->route('admin.laboratory-purchases.index')
            ->flashMessage('Orden de laboratorio eliminada correctamente.');
    }
}
