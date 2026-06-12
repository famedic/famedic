<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Addresses\CreateAddressAction;
use App\Actions\Addresses\DestroyAddressAction;
use App\Actions\Addresses\UpdateAddressAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\User\StoreAddressRequest;
use App\Http\Requests\Api\V1\User\UpdateAddressRequest;
use App\Http\Resources\Api\V1\AddressResource;
use App\Http\Responses\ApiResponse;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserAddressController extends Controller
{
    public function store(
        StoreAddressRequest $request,
        CreateAddressAction $createAddressAction,
    ): JsonResponse {
        $address = $createAddressAction(
            street: $request->validated('street'),
            number: $request->validated('number'),
            neighborhood: $request->validated('neighborhood'),
            state: $request->validated('state'),
            city: $request->validated('city'),
            zipcode: $request->validated('zipcode'),
            additional_references: $request->validated('additional_references'),
            customer: $request->user()->customer,
        );

        return ApiResponse::success([
            'address' => (new AddressResource($address))->resolve($request),
        ], status: 201);
    }

    public function update(
        UpdateAddressRequest $request,
        int $addressId,
        UpdateAddressAction $updateAddressAction,
    ): JsonResponse {
        $address = $this->findOwnedAddress($request, $addressId);

        if ($address instanceof JsonResponse) {
            return $address;
        }

        $address = $updateAddressAction(
            street: $request->validated('street'),
            number: $request->validated('number'),
            neighborhood: $request->validated('neighborhood'),
            state: $request->validated('state'),
            city: $request->validated('city'),
            zipcode: $request->validated('zipcode'),
            additional_references: $request->validated('additional_references'),
            address: $address,
        );

        return ApiResponse::success([
            'address' => (new AddressResource($address))->resolve($request),
        ]);
    }

    public function destroy(
        Request $request,
        int $addressId,
        DestroyAddressAction $destroyAddressAction,
    ): JsonResponse {
        $address = $this->findOwnedAddress($request, $addressId);

        if ($address instanceof JsonResponse) {
            return $address;
        }

        $destroyAddressAction($address);

        return ApiResponse::success(['deleted' => true]);
    }

    private function findOwnedAddress(Request $request, int $addressId): Address|JsonResponse
    {
        $address = Address::query()
            ->where('id', $addressId)
            ->where('customer_id', $request->user()->customer->id)
            ->first();

        if (! $address) {
            return ApiResponse::error(
                'ADDRESS_NOT_FOUND',
                'La dirección no fue encontrada.',
                404,
            );
        }

        return $address;
    }
}
