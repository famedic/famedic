<?php

namespace App\Http\Controllers\OnlinePharmacy\OnlinePharmacyPurchases;

use App\Actions\CreateInvoiceRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\OnlinePharmacy\OnlinePharmacyPurchases\StoreInvoiceRequestRequest;
use App\Models\OnlinePharmacyPurchase;

class InvoiceRequestController extends Controller
{
    public function __invoke(StoreInvoiceRequestRequest $request, OnlinePharmacyPurchase $onlinePharmacyPurchase, CreateInvoiceRequestAction $action)
    {
        $action($onlinePharmacyPurchase, auth()->user()->customer->taxProfiles()->find($request->tax_profile));

        return redirect()->route('online-pharmacy-purchases.show', [
            'online_pharmacy_purchase' => $onlinePharmacyPurchase,
        ])->flashMessage('Se ha solicitado la factura.');
    }
}
