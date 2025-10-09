<?php

namespace App\Http\Controllers\Admin\LaboratoryPurchases;

use App\Actions\CreateDevAssistanceRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryPurchases\StoreDevAssistanceRequestRequest;
use App\Models\LaboratoryPurchase;

class DevAssistanceRequestController extends Controller
{
    public function __invoke(
        StoreDevAssistanceRequestRequest $request,
        LaboratoryPurchase $laboratoryPurchase,
        CreateDevAssistanceRequestAction $action
    ) {
        $action(
            $laboratoryPurchase,
            $request->user()->administrator,
            $request->comment
        );

        return redirect()->route('admin.laboratory-purchases.show', [
            'laboratory_purchase' => $laboratoryPurchase,
        ])->flashMessage('Tu solicitud ha sido enviada');
    }
}
