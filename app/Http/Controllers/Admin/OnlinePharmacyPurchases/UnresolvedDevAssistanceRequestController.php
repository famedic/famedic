<?php

namespace App\Http\Controllers\Admin\OnlinePharmacyPurchases;

use App\Actions\ReopenDevAssistanceRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OnlinePharmacyPurchases\StoreDevAssistanceRequestRequest;
use App\Models\DevAssistanceRequest;
use App\Models\OnlinePharmacyPurchase;

class UnresolvedDevAssistanceRequestController extends Controller
{
    public function __invoke(
        StoreDevAssistanceRequestRequest $request,
        OnlinePharmacyPurchase $onlinePharmacyPurchase,
        DevAssistanceRequest $devAssistanceRequest,
        ReopenDevAssistanceRequestAction $action
    ) {
        $action(
            $devAssistanceRequest,
            $request->user()->administrator,
            $request->comment
        );

        return redirect()->route('admin.online-pharmacy-purchases.show', [
            'online_pharmacy_purchase' => $onlinePharmacyPurchase,
        ])->flashMessage('Solicitud reabierta');
    }
}
