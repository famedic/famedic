<?php

namespace App\Http\Controllers\Admin\OnlinePharmacyPurchases;

use App\Actions\AddDevAssistanceCommentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OnlinePharmacyPurchases\StoreDevAssistanceRequestRequest;
use App\Models\DevAssistanceRequest;
use App\Models\OnlinePharmacyPurchase;

class ResolvedDevAssistanceRequestController extends Controller
{
    public function __invoke(
        StoreDevAssistanceRequestRequest $request,
        OnlinePharmacyPurchase $onlinePharmacyPurchase,
        DevAssistanceRequest $devAssistanceRequest,
        AddDevAssistanceCommentAction $action
    ) {
        $action(
            $devAssistanceRequest,
            $request->user()->administrator,
            $request->comment,
            $request->boolean('mark_resolved', false)
        );

        return redirect()->route('admin.online-pharmacy-purchases.show', [
            'online_pharmacy_purchase' => $onlinePharmacyPurchase,
        ])->flashMessage($request->boolean('mark_resolved', false) ? 'Solicitud completada' : 'Tu comentario fue añadido');
    }
}
