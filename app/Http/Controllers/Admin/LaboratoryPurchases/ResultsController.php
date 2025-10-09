<?php

namespace App\Http\Controllers\Admin\LaboratoryPurchases;

use App\Actions\CreateResultsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryPurchases\StoreResultsRequest;
use App\Models\LaboratoryPurchase;

class ResultsController extends Controller
{
    public function __invoke(StoreResultsRequest $request, LaboratoryPurchase $laboratoryPurchase, CreateResultsAction $action)
    {
        $action($laboratoryPurchase, $request->file('results'));

        return redirect()->route('admin.laboratory-purchases.show', [
            'laboratory_purchase' => $laboratoryPurchase,
        ])->flashMessage('Resultados guardados exitosamente.');
    }
}
