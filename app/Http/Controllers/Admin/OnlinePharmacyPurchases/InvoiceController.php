<?php

namespace App\Http\Controllers\Admin\OnlinePharmacyPurchases;

use App\Actions\CreateInvoiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OnlinePharmacyPurchases\StoreInvoiceRequest;
use App\Models\OnlinePharmacyPurchase;

class InvoiceController extends Controller
{
    public function __invoke(StoreInvoiceRequest $request, OnlinePharmacyPurchase $onlinePharmacyPurchase, CreateInvoiceAction $action)
    {
        $action($onlinePharmacyPurchase, $request->file('invoice'));

        return redirect()->route('admin.online-pharmacy-purchases.show', [
            'online_pharmacy_purchase' => $onlinePharmacyPurchase,
        ])->flashMessage('Factura guardada exitosamente.');
    }
}
