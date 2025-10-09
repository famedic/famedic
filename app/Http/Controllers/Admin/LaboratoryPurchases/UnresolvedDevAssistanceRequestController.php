<?php

namespace App\Http\Controllers\Admin\LaboratoryPurchases;

use App\Actions\ReopenDevAssistanceRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryPurchases\StoreDevAssistanceRequestRequest;
use App\Models\DevAssistanceRequest;
use App\Models\LaboratoryPurchase;

class UnresolvedDevAssistanceRequestController extends Controller
{
    public function __invoke(
        StoreDevAssistanceRequestRequest $request,
        LaboratoryPurchase $laboratoryPurchase,
        DevAssistanceRequest $devAssistanceRequest,
        ReopenDevAssistanceRequestAction $action
    ) {
        $action(
            $devAssistanceRequest,
            $request->user()->administrator,
            $request->comment
        );

        return redirect()->route('admin.laboratory-purchases.show', [
            'laboratory_purchase' => $laboratoryPurchase,
        ])->flashMessage('Solicitud reabierta');
    }
}
