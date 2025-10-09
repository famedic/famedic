<?php

namespace App\Http\Controllers\Admin\LaboratoryPurchases;

use App\Actions\Admin\VendorPayments\BuildVendorPaymentDetailsAction;
use App\Actions\CreateVendorPaymentAndAttachPurchasesAction;
use App\Actions\DeleteVendorPaymentAction;
use App\Actions\UpdateVendorPaymentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryPurchases\CreateVendorPaymentRequest;
use App\Http\Requests\Admin\LaboratoryPurchases\DestroyVendorPaymentRequest;
use App\Http\Requests\Admin\LaboratoryPurchases\EditVendorPaymentRequest;
use App\Http\Requests\Admin\LaboratoryPurchases\IndexVendorPaymentRequest;
use App\Http\Requests\Admin\LaboratoryPurchases\ShowVendorPaymentRequest;
use App\Http\Requests\Admin\LaboratoryPurchases\StoreVendorPaymentRequest;
use App\Http\Requests\Admin\LaboratoryPurchases\UpdateVendorPaymentRequest;
use App\Models\LaboratoryPurchase;
use App\Models\VendorPayment;
use Carbon\Carbon;
use Inertia\Inertia;

class VendorPaymentsController extends Controller
{
    public function index(IndexVendorPaymentRequest $request)
    {
        $filters = collect($request->only('search', 'start_date', 'end_date'))->filter()->all();

        $vendorPayments = VendorPayment::with([
            'laboratoryPurchases:id,gda_order_id,total_cents',
            'laboratoryPurchases.transactions',
            'onlinePharmacyPurchases:id',
        ])
            ->where('purchase_type', \App\Enums\VendorPaymentPurchaseType::LABORATORY)
            ->withCount('laboratoryPurchases')
            ->filter($filters)
            ->latest('paid_at')
            ->paginate();

        if (! empty($filters['start_date'])) {
            $filters['formatted_start_date'] = Carbon::parse($filters['start_date'])->isoFormat('D MMM YYYY');
        }

        if (! empty($filters['end_date'])) {
            $filters['formatted_end_date'] = Carbon::parse($filters['end_date'])->isoFormat('D MMM YYYY');
        }

        return Inertia::render('Admin/VendorPayments', [
            'vendorPayments' => $vendorPayments,
            'filters' => $filters,
        ]);
    }

    public function create(CreateVendorPaymentRequest $request, BuildVendorPaymentDetailsAction $buildVendorPaymentDetailsAction)
    {
        $filters = collect($request->only('search', 'start_date', 'end_date'))
            ->filter()
            ->all();

        $purchases = LaboratoryPurchase::with(['vendorPayments', 'transactions'])
            ->filter($filters)
            ->latest()
            ->paginate()
            ->withQueryString();

        if (! empty($filters['start_date'])) {
            $filters['formatted_start_date'] = Carbon::parse($filters['start_date'], 'America/Monterrey')->isoFormat('MMM D, Y');
        }

        if (! empty($filters['end_date'])) {
            $filters['formatted_end_date'] = Carbon::parse($filters['end_date'], 'America/Monterrey')->isoFormat('MMM D, Y');
        }

        return Inertia::render('Admin/LaboratoryVendorPaymentCreate', [
            'selectedPurchasesDetails' => $buildVendorPaymentDetailsAction(
                LaboratoryPurchase::with('transactions')->whereIn('id', $request->input('purchase_ids', []))->get()
            ),
            'purchases' => $purchases,
            'filters' => $filters,
        ]);
    }

    public function store(
        StoreVendorPaymentRequest $request,
        CreateVendorPaymentAndAttachPurchasesAction $createVendorPaymentAndAttachAction
    ) {
        ($createVendorPaymentAndAttachAction)(
            purchases: LaboratoryPurchase::whereIn('id', $request->purchase_ids)->get(),
            paidAt: $request->validated('paid_at'),
            proof: $request->file('proof')
        );

        return redirect()->route('admin.laboratory-purchases.vendor-payments.index')
            ->flashMessage('Pago a proveedor registrado y asociado correctamente.');
    }

    public function show(ShowVendorPaymentRequest $request, VendorPayment $vendorPayment)
    {
        $vendorPayment->load([
            'laboratoryPurchases:id,gda_order_id,total_cents',
            'laboratoryPurchases.transactions',
        ]);

        $purchases = LaboratoryPurchase::whereHas('vendorPayments', function ($query) use ($vendorPayment) {
            $query->where('vendor_payments.id', $vendorPayment->id);
        })
            ->with([
                'laboratoryPurchaseItems',
                'transactions',
                'invoiceRequest',
                'vendorPayments',
                'devAssistanceRequests',
            ])
            ->latest()
            ->get();

        return Inertia::render('Admin/VendorPaymentShow', [
            'vendorPayment' => $vendorPayment,
            'purchases' => $purchases,
        ]);
    }

    public function edit(EditVendorPaymentRequest $request, VendorPayment $vendorPayment, BuildVendorPaymentDetailsAction $buildVendorPaymentDetailsAction)
    {
        $vendorPayment->load(['laboratoryPurchases:id,gda_order_id,total_cents']);

        $filters = collect($request->only('search', 'start_date', 'end_date'))
            ->filter()
            ->all();

        $purchases = LaboratoryPurchase::with(['vendorPayments', 'transactions'])
            ->filter($filters)
            ->latest()
            ->paginate()
            ->withQueryString();

        if (! empty($filters['start_date'])) {
            $filters['formatted_start_date'] = Carbon::parse($filters['start_date'], 'America/Monterrey')->isoFormat('MMM D, Y');
        }

        if (! empty($filters['end_date'])) {
            $filters['formatted_end_date'] = Carbon::parse($filters['end_date'], 'America/Monterrey')->isoFormat('MMM D, Y');
        }

        return Inertia::render('Admin/VendorPaymentEdit', [
            'vendorPayment' => $vendorPayment,
            'purchases' => $purchases,
            'selectedPurchasesDetails' => $buildVendorPaymentDetailsAction(
                LaboratoryPurchase::with('transactions')->whereIn('id', $request->input('purchase_ids', $vendorPayment->laboratoryPurchases->pluck('id')->toArray()))->get()
            ),
            'filters' => $filters,
        ]);
    }

    public function update(
        UpdateVendorPaymentRequest $request,
        VendorPayment $vendorPayment,
        UpdateVendorPaymentAction $updateVendorPaymentAction
    ) {
        $vendorPayment = ($updateVendorPaymentAction)(
            vendorPayment: $vendorPayment,
            purchaseIds: $request->purchase_ids,
            purchaseModel: LaboratoryPurchase::class,
            paidAt: $request->paid_at,
            proof: $request->file('proof')
        );

        return redirect()->route('admin.laboratory-purchases.vendor-payments.show', $vendorPayment)
            ->flashMessage('Pago a proveedor actualizado correctamente.');
    }

    public function destroy(
        DestroyVendorPaymentRequest $request,
        VendorPayment $vendorPayment,
        DeleteVendorPaymentAction $deleteVendorPaymentAction
    ) {
        ($deleteVendorPaymentAction)($vendorPayment);

        return redirect()->route('admin.laboratory-purchases.vendor-payments.index')
            ->flashMessage('Pago a proveedor eliminado correctamente.');
    }
}
