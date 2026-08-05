<?php

namespace App\Http\Controllers\Admin\LaboratoryPurchases;

use App\Actions\CreateInvoiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryPurchases\StoreInvoiceRequest;
use App\Models\LaboratoryPurchase;
use App\Services\Audit\Business\BillingInvoiceDocumentsAuditHint;
use App\Services\Audit\Business\BusinessAuditChannel;

class InvoiceController extends Controller
{
    public function __invoke(StoreInvoiceRequest $request, LaboratoryPurchase $laboratoryPurchase, CreateInvoiceAction $action)
    {
        $action(
            $laboratoryPurchase,
            $request->file('invoice'),
            $request->file('invoice_xml'),
            new BillingInvoiceDocumentsAuditHint(
                channel: BusinessAuditChannel::ADMIN_WEB,
                purchaseType: BillingInvoiceDocumentsAuditHint::PURCHASE_TYPE_LABORATORY,
                purchaseId: (int) $laboratoryPurchase->id,
                actorAdminUserId: (int) $request->user()->id,
                subjectCustomerId: (int) $laboratoryPurchase->customer_id,
            ),
        );

        return redirect()->route('admin.laboratory-purchases.show', [
            'laboratory_purchase' => $laboratoryPurchase,
        ])->flashMessage('Factura guardada exitosamente.');
    }
}
