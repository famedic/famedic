<?php

namespace App\Http\Controllers\Admin\OnlinePharmacyPurchases;

use App\Actions\CreateDevAssistanceRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OnlinePharmacyPurchases\StoreDevAssistanceRequestRequest;
use App\Models\OnlinePharmacyPurchase;

class DevAssistanceRequestController extends Controller
{
    public function __invoke(
        StoreDevAssistanceRequestRequest $request,
        OnlinePharmacyPurchase $onlinePharmacyPurchase,
        CreateDevAssistanceRequestAction $action
    ) {
        $action(
            $onlinePharmacyPurchase,
            $request->user()->administrator,
            $request->comment
        );

        return redirect()->route('admin.online-pharmacy-purchases.show', [
            'online_pharmacy_purchase' => $onlinePharmacyPurchase,
        ])->flashMessage('Tu solicitud ha sido enviada');
    }
}
