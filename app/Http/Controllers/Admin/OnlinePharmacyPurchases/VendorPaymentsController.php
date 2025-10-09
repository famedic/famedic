<?php

namespace App\Http\Controllers\Admin\OnlinePharmacyPurchases;

use App\Actions\Admin\VendorPayments\BuildVendorPaymentDetailsAction;
use App\Actions\CreateVendorPaymentAndAttachPurchasesAction;
use App\Actions\DeleteVendorPaymentAction;
use App\Actions\UpdateVendorPaymentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OnlinePharmacyPurchases\CreateVendorPaymentRequest;
use App\Http\Requests\Admin\OnlinePharmacyPurchases\DestroyVendorPaymentRequest;
use App\Http\Requests\Admin\OnlinePharmacyPurchases\EditVendorPaymentRequest;
use App\Http\Requests\Admin\OnlinePharmacyPurchases\IndexVendorPaymentRequest;
use App\Http\Requests\Admin\OnlinePharmacyPurchases\ShowVendorPaymentRequest;
use App\Http\Requests\Admin\OnlinePharmacyPurchases\StoreVendorPaymentRequest;
use App\Http\Requests\Admin\OnlinePharmacyPurchases\UpdateVendorPaymentRequest;
use App\Models\OnlinePharmacyPurchase;
use App\Models\VendorPayment;
use Carbon\Carbon;
use Inertia\Inertia;

class VendorPaymentsController extends Controller
{
    public function index(IndexVendorPaymentRequest $request)
    {
        $filters = collect($request->only('search', 'start_date', 'end_date'))
            ->filter()
            ->all();

        $vendorPayments = VendorPayment::with([
            'onlinePharmacyPurchases:id,vitau_order_id,total_cents',
            'onlinePharmacyPurchases.transactions',
            'laboratoryPurchases:id',
        ])
            ->where('purchase_type', \App\Enums\VendorPaymentPurchaseType::ONLINE_PHARMACY)
            ->withCount('onlinePharmacyPurchases')
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

        $purchases = OnlinePharmacyPurchase::with(['vendorPayments', 'transactions'])
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

        return Inertia::render('Admin/OnlinePharmacyVendorPaymentCreate', [
            'selectedPurchasesDetails' => $buildVendorPaymentDetailsAction(
                OnlinePharmacyPurchase::with('transactions')->whereIn('id', $request->input('purchase_ids', []))->get()
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
            purchases: OnlinePharmacyPurchase::whereIn('id', $request->purchase_ids)->get(),
            paidAt: $request->validated('paid_at'),
            proof: $request->file('proof')
        );

        return redirect()->route('admin.online-pharmacy-purchases.vendor-payments.index')
            ->flashMessage('Pago a proveedor registrado y asociado correctamente.');
    }

    public function show(ShowVendorPaymentRequest $request, VendorPayment $vendorPayment)
    {
        $vendorPayment->load([
            'onlinePharmacyPurchases:id,vitau_order_id,total_cents',
            'onlinePharmacyPurchases.transactions',
        ]);

        $purchases = OnlinePharmacyPurchase::whereHas('vendorPayments', function ($query) use ($vendorPayment) {
            $query->where('vendor_payments.id', $vendorPayment->id);
        })
            ->with([
                'onlinePharmacyPurchaseItems',
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
        $vendorPayment->load(['onlinePharmacyPurchases:id,vitau_order_id,total_cents']);

        $filters = collect($request->only('search', 'start_date', 'end_date'))
            ->filter()
            ->all();

        $purchases = OnlinePharmacyPurchase::with(['vendorPayments', 'transactions'])
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
                OnlinePharmacyPurchase::with('transactions')->whereIn('id', $request->input('purchase_ids', $vendorPayment->onlinePharmacyPurchases->pluck('id')->toArray()))->get()
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
            purchaseModel: OnlinePharmacyPurchase::class,
            paidAt: $request->paid_at,
            proof: $request->file('proof')
        );

        return redirect()->route('admin.online-pharmacy-purchases.vendor-payments.show', $vendorPayment)
            ->flashMessage('Pago a proveedor actualizado correctamente.');
    }

    public function destroy(
        DestroyVendorPaymentRequest $request,
        VendorPayment $vendorPayment,
        DeleteVendorPaymentAction $deleteVendorPaymentAction
    ) {
        ($deleteVendorPaymentAction)($vendorPayment);

        return redirect()->route('admin.online-pharmacy-purchases.vendor-payments.index')
            ->flashMessage('Pago a proveedor eliminado correctamente.');
    }
}
