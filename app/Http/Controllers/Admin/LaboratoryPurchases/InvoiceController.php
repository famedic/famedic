<?php

namespace App\Http\Controllers\Admin\LaboratoryPurchases;

use App\Actions\CreateInvoiceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryPurchases\StoreInvoiceRequest;
use App\Models\LaboratoryPurchase;

class InvoiceController extends Controller
{
    public function __invoke(StoreInvoiceRequest $request, LaboratoryPurchase $laboratoryPurchase, CreateInvoiceAction $action)
    {
        $action($laboratoryPurchase, $request->file('invoice'));

        return redirect()->route('admin.laboratory-purchases.show', [
            'laboratory_purchase' => $laboratoryPurchase,
        ])->flashMessage('Factura guardada exitosamente.');
    }
}
