<?php

namespace App\Actions\Addresses;

use App\Models\Address;

class UpdateAddressAction
{
    public function __invoke(
        string $street,
        string $number,
        string $neighborhood,
        string $state,
        string $city,
        string $zipcode,
        ?string $additional_references,
        Address $address,
    ): Address {
        $address->update([
            'street' => $street,
            'number' => $number,
            'neighborhood' => $neighborhood,
            'state' => $state,
            'city' => $city,
            'zipcode' => $zipcode,
            'additional_references' => $additional_references,
        ]);

        return $address;
    }
}
