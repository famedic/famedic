<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\AddItemToCartAction;
use App\Actions\Laboratories\DeleteItemFromCartAction;
use App\Http\Requests\Laboratories\LaboratoryCartItems\DestroyLaboratoryCartItemRequest;
use App\Http\Requests\Laboratories\LaboratoryCartItems\StoreLaboratoryCartItemRequest;
use App\Models\LaboratoryCartItem;
use App\Services\Tracking\AddToCart;

class LaboratoryCartItemController extends Controller
{
    public function store(
        StoreLaboratoryCartItemRequest $request,
        AddItemToCartAction $action
    ) {
        $laboratoryCartItem = $action(
            customer: auth()->user()->customer,
            laboratoryTestId: $request->laboratory_test
        );

        AddToCart::track(
            productId: $laboratoryCartItem->laboratoryTest->gda_id,
            value: $laboratoryCartItem->laboratoryTest->famedic_price,
            source: 'laboratory',
            customProperties: [
                'brand'  => $laboratoryCartItem->laboratoryTest->brand->value,
            ],
        );

        return redirect()
            ->back()
            ->flashMessage('Estudio de laboratorio agregado exitosamente.');
    }

    public function destroy(
        DestroyLaboratoryCartItemRequest $request,
        LaboratoryCartItem $laboratoryCartItem,
        DeleteItemFromCartAction $action
    ) {
        $action($laboratoryCartItem);

        return redirect()
            ->back()
            ->flashMessage('Estudio de laboratorio eliminado exitosamente.');
    }
}
