<?php

namespace App\Http\Controllers\Admin\OnlinePharmacyPurchases;

use App\Actions\CreateInvoiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OnlinePharmacyPurchases\StoreInvoiceRequest;
use App\Models\OnlinePharmacyPurchase;
use App\Services\Audit\Business\BillingInvoiceDocumentsAuditHint;
use App\Services\Audit\Business\BusinessAuditChannel;

class InvoiceController extends Controller
{
    public function __invoke(StoreInvoiceRequest $request, OnlinePharmacyPurchase $onlinePharmacyPurchase, CreateInvoiceAction $action)
    {
        $action(
            $onlinePharmacyPurchase,
            $request->file('invoice'),
            $request->file('invoice_xml'),
            new BillingInvoiceDocumentsAuditHint(
                channel: BusinessAuditChannel::ADMIN_WEB,
                purchaseType: BillingInvoiceDocumentsAuditHint::PURCHASE_TYPE_PHARMACY,
                purchaseId: (int) $onlinePharmacyPurchase->id,
                actorAdminUserId: (int) $request->user()->id,
                subjectCustomerId: (int) $onlinePharmacyPurchase->customer_id,
            ),
        );

        return redirect()->route('admin.online-pharmacy-purchases.show', [
            'online_pharmacy_purchase' => $onlinePharmacyPurchase,
        ])->flashMessage('Factura guardada exitosamente.');
    }
}
