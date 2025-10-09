<?php

namespace App\Actions\Addresses;

use App\Models\Customer;
use App\Models\Address;

class CreateAddressAction
{
    public function __invoke(
        string $street,
        string $number,
        string $neighborhood,
        string $state,
        string $city,
        string $zipcode,
        ?string $additional_references,
        Customer $customer
    ): Address {
        return $customer->addresses()->create([
            'street' => $street,
            'number' => $number,
            'neighborhood' => $neighborhood,
            'state' => $state,
            'city' => $city,
            'zipcode' => $zipcode,
            'additional_references' => $additional_references,
        ]);
    }
}
