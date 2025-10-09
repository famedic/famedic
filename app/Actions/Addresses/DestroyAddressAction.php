<?php

namespace App\Actions\Addresses;

use App\Models\Address;

class DestroyAddressAction
{
    public function __invoke(
        Address $address
    ): void {
        $address->delete();
    }
}
