<?php

namespace App\Http\Controllers\Admin\LaboratoryPurchases;

use App\Actions\AddDevAssistanceCommentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryPurchases\StoreDevAssistanceRequestRequest;
use App\Models\DevAssistanceRequest;
use App\Models\LaboratoryPurchase;

class ResolvedDevAssistanceRequestController extends Controller
{
    public function __invoke(
        StoreDevAssistanceRequestRequest $request,
        LaboratoryPurchase $laboratoryPurchase,
        DevAssistanceRequest $devAssistanceRequest,
        AddDevAssistanceCommentAction $action
    ) {
        $action(
            $devAssistanceRequest,
            $request->user()->administrator,
            $request->comment,
            $request->boolean('mark_resolved', false)
        );

        return redirect()->route('admin.laboratory-purchases.show', [
            'laboratory_purchase' => $laboratoryPurchase,
        ])->flashMessage($request->boolean('mark_resolved', false) ? 'Solicitud completada' : 'Tu comentario fue añadido');
    }
}
