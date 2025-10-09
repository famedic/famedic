<?php

namespace App\Http\Controllers;

use App\Actions\OnlinePharmacy\AddItemToCartAction;
use App\Actions\OnlinePharmacy\DeleteItemFromCartAction;
use App\Actions\OnlinePharmacy\UpdateItemToCartAction;
use App\Http\Requests\OnlinePharmacy\OnlinePharmacyCartItems\DestroyOnlinePharmacyCartItemRequest;
use App\Http\Requests\OnlinePharmacy\OnlinePharmacyCartItems\StoreOnlinePharmacyCartItemRequest;
use App\Http\Requests\OnlinePharmacy\OnlinePharmacyCartItems\UpdateOnlinePharmacyCartItemRequest;
use App\Models\OnlinePharmacyCartItem;
use App\Services\Tracking\AddToCart;

class OnlinePharmacyCartItemController extends Controller
{
    public function store(
        StoreOnlinePharmacyCartItemRequest $request,
        AddItemToCartAction $action
    ) {
        $onlinePharmacyCartItem = $action(customer: auth()->user()->customer, vitauProductId: $request->vitau_product);

        AddToCart::track(
            productId: (string)$onlinePharmacyCartItem->vitau_product_id,
            value: $onlinePharmacyCartItem->price,
            source: 'online-pharmacy',
            customProperties: [],
        );

        return redirect()
            ->back()
            ->flashMessage('Producto agregado exitosamente.');
    }

    public function update(
        UpdateOnlinePharmacyCartItemRequest $request,
        OnlinePharmacyCartItem $onlinePharmacyCartItem,
        UpdateItemToCartAction $action
    ) {
        $action(
            onlinePharmacyCartItem: $onlinePharmacyCartItem,
            quantity: $request->quantity
        );

        AddToCart::track(
            productId: $onlinePharmacyCartItem->vitau_product_id,
            value: floatval(str_replace(',', '', $onlinePharmacyCartItem->price)) * $request->quantity,
            source: 'online-pharmacy',
            customProperties: [],
            quantity: $request->quantity,
        );

        return redirect()
            ->back()
            ->flashMessage('Producto actualizado exitosamente.');
    }

    public function destroy(
        DestroyOnlinePharmacyCartItemRequest $request,
        OnlinePharmacyCartItem $onlinePharmacyCartItem,
        DeleteItemFromCartAction $action
    ) {
        $action(onlinePharmacyCartItem: $onlinePharmacyCartItem);

        return redirect()
            ->back()
            ->flashMessage('Producto eliminado exitosamente.');
    }
}
