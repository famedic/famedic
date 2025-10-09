<?php

namespace Tests\Browser\Pages;

use App\Models\Address;
use Laravel\Dusk\Browser;
use Laravel\Dusk\Page;

class Addresses extends Page
{
    public function url(): string
    {
        return '/addresses';
    }

    public function assert(Browser $browser): void
    {
        $browser->assertPathIs($this->url())->assertSee('Mis direcciones');
    }

    public function openAddressForm(Browser $browser, ?Address $address = null): void
    {
        if ($address) {
            $browser->click("@editAddress-{$address->id}")
                ->waitForText('Edita tu dirección');
        } else {
            $browser->click('@createAddress')
                ->waitForText('Agregar dirección');
        }
    }

    public function openAddressDeleteConfirmation(Browser $browser, Address $address): void
    {
        $browser->click("@deleteAddress-{$address->id}")
            ->waitForText('Eliminar dirección "'.$address->street.' '.$address->number.'"');
    }

    public function typeAddress(Browser $browser, string $street, string $number, string $neighborhood, string $state, string $city, string $zipcode, string $additionalReferences): void
    {
        $browser->type('@street', $street)
            ->type('@number', $number)
            ->type('@neighborhood', $neighborhood)
            ->select('@state', $state)
            ->select('@city', $city)
            ->type('@zipcode', $zipcode)
            ->type('@additionalReferences', $additionalReferences)
            ->press('@saveAddress');
    }

    public function clearAddress(Browser $browser): void
    {
        $browser->clearInput('@street')
            ->clearInput('@number')
            ->clearInput('@neighborhood')
            ->clearInput('@zipcode')
            ->clearInput('@additionalReferences');
    }

    public function deleteAddress(Browser $browser, Address $address): void
    {
        $browser->openAddressDeleteConfirmation($address)
            ->press('@delete');
    }
}
