<?php

namespace App\Http\Controllers\Checkout;

use App\Actions\Addresses\CreateAddressAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Addresses\StoreAddressRequest;

class AddressController extends Controller
{
    public function __invoke(StoreAddressRequest $request, CreateAddressAction $action)
    {
        $address = $action(
            street: $request->street,
            number: $request->number,
            neighborhood: $request->neighborhood,
            state: $request->state,
            city: $request->city,
            zipcode: $request->zipcode,
            additional_references: $request->additional_references,
            customer: $request->user()->customer
        );

        return response()->json([
            'address' => $address->id
        ]);
    }
}
