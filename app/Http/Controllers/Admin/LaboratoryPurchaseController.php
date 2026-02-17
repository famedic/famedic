<?php

namespace App\Http\Controllers\Admin;

use App\Actions\BuildDailyChartDataAction;
use App\Actions\Laboratories\DeleteLaboratoryPurchaseAction;
use App\Enums\LaboratoryBrand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryPurchases\DestroyLaboratoryPurchaseRequest;
use App\Http\Requests\Admin\LaboratoryPurchases\IndexLaboratoryPurchaseRequest;
use App\Http\Requests\Admin\LaboratoryPurchases\ShowLaboratoryPurchaseRequest;
use Illuminate\Support\Facades\Log;
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

        // Aplicar filtro de fecha por defecto (último año en ambiente local)
        if (empty($filters['start_date']) && empty($filters['end_date']) && app()->environment('local')) {
            $filters['start_date'] = Carbon::now('America/Monterrey')->subYear()->startOfDay()->toDateString();
            $filters['end_date'] = Carbon::now('America/Monterrey')->endOfDay()->toDateString();
        }

        $laboratoryPurchasesQuery = LaboratoryPurchase::with([
            'transactions',
            'vendorPayments',
            'laboratoryPurchaseItems',
            'customer.user',
            'invoice',
            'invoiceRequest',
            'devAssistanceRequests',
        ])->filter($filters);

        // Obtener datos para el chart (solo si hay fechas definidas para evitar cargar todo)
        $laboratoryDailyChart = null;
        if (!empty($filters['start_date']) || !empty($filters['end_date'])) {
            $purchasesForChart = (clone $laboratoryPurchasesQuery)->get();

            $laboratoryDailyChart = $buildDailyChartDataAction(
                $purchasesForChart,
                $request->start_date ? Carbon::parse($request->start_date, 'America/Monterrey') : null,
                $request->end_date ? Carbon::parse($request->end_date, 'America/Monterrey') : null
            );
        }

        $laboratoryPurchases = $laboratoryPurchasesQuery->latest()->paginate()->withQueryString();

        if (!empty($filters['start_date'])) {
            $filters['formatted_start_date'] = Carbon::parse($filters['start_date'], 'America/Monterrey')->isoFormat('MMM D, Y');
        }

        if (!empty($filters['end_date'])) {
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

    public function destroy(
        DestroyLaboratoryPurchaseRequest $request,
        LaboratoryPurchase $laboratoryPurchase,
        DeleteLaboratoryPurchaseAction $deleteLaboratoryPurchaseAction
    ) {
        Log::info('🗑️ LaboratoryPurchaseController@destroy INICIO', [
            'laboratory_purchase_id' => $laboratoryPurchase->id,
            'gda_order_id' => $laboratoryPurchase->gda_order_id,
            'transactions_count' => $laboratoryPurchase->transactions->count(),
            'user_id' => $request->user()->id,
        ]);

        try {
            ($deleteLaboratoryPurchaseAction)($laboratoryPurchase);

            Log::info('✅ LaboratoryPurchaseController@destroy COMPLETADO', [
                'laboratory_purchase_id' => $laboratoryPurchase->id,
            ]);

            return redirect()->route('admin.laboratory-purchases.index')
                ->flashMessage('Orden de laboratorio eliminada correctamente.');

        } catch (\Exception $e) {

            Log::error('❌ LaboratoryPurchaseController@destroy ERROR', [
                'laboratory_purchase_id' => $laboratoryPurchase->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors([
                'error' => 'No se pudo cancelar el pedido: ' . $e->getMessage()
            ]);
        }
    }

}
