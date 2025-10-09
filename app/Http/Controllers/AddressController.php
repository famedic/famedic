<?php

namespace App\Http\Controllers;

use App\Actions\Addresses\CreateAddressAction;
use App\Actions\Addresses\DestroyAddressAction;
use App\Actions\Addresses\UpdateAddressAction;
use App\Http\Requests\Addresses\DestroyAddressRequest;
use App\Http\Requests\Addresses\EditAddressRequest;
use App\Http\Requests\Addresses\StoreAddressRequest;
use App\Http\Requests\Addresses\UpdateAddressRequest;
use App\Models\Address;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AddressController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Addresses', [
            'addresses' => $request->user()->customer->addresses,
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Addresses', [
            'addresses' => $request->user()->customer->addresses,
            'mexicanStates' => config('mexicanstates'),
        ]);
    }

    public function store(StoreAddressRequest $request, CreateAddressAction $action)
    {
        $action(
            street: $request->street,
            number: $request->number,
            neighborhood: $request->neighborhood,
            state: $request->state,
            city: $request->city,
            zipcode: $request->zipcode,
            additional_references: $request->additional_references,
            customer: $request->user()->customer
        );

        return redirect()->route('addresses.index')
            ->flashMessage('Dirección guardada exitosamente.');
    }

    public function edit(EditAddressRequest $request, Address $address)
    {
        return Inertia::render('Addresses', [
            'address' => $address,
            'addresses' => $request->user()->customer->addresses,
            'mexicanStates' => config('mexicanstates'),
        ]);
    }

    public function update(UpdateAddressRequest $request, Address $address, UpdateAddressAction $action)
    {
        $action(
            street: $request->street,
            number: $request->number,
            neighborhood: $request->neighborhood,
            state: $request->state,
            city: $request->city,
            zipcode: $request->zipcode,
            additional_references: $request->additional_references,
            address: $address
        );

        return redirect()->route('addresses.index')
            ->flashMessage('Dirección actualizada exitosamente.');
    }

    public function destroy(DestroyAddressRequest $request, Address $address, DestroyAddressAction $action)
    {
        $action($address);

        return redirect()->route('addresses.index')
            ->flashMessage('Dirección eliminada exitosamente.');
    }
}
