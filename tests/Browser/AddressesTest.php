<?php

use App\Models\Address;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\Browser\Pages\Addresses;

test('user can create address', function () {
    $user = User::factory()->withRegularCustomer()->withCompleteProfile()->create();

    $addressData = Address::factory()->make([
        'customer_id' => $user->customer->id,
    ]);

    expect(Address::ofCustomer($user->customer)->count())->toBe(0);

    $this->browse(function (Browser $browser) use ($user, $addressData) {
        $browser->loginAs($user)
            ->visit(new Addresses)
            ->openAddressForm()
            ->typeAddress(
                street: $addressData->street,
                number: $addressData->number,
                neighborhood: $addressData->neighborhood,
                state: $addressData->state,
                city: $addressData->city,
                zipcode: $addressData->zipcode,
                additionalReferences: $addressData->additional_references,
            )
            ->waitForText('Dirección guardada exitosamente.');
    });

    expect(Address::ofCustomer($user->customer)->count())->toBe(1);
    $address = $user->customer->addresses()->first();

    expect($address->street)->toBe($addressData->street);
    expect($address->number)->toBe($addressData->number);
    expect($address->neighborhood)->toBe($addressData->neighborhood);
    expect($address->state)->toBe($addressData->state);
    expect($address->city)->toBe($addressData->city);
    expect($address->zipcode)->toBe($addressData->zipcode);
    expect($address->additional_references)->toBe($addressData->additional_references);
});

test('user cannot create address with empty values', function () {
    $user = User::factory()->withRegularCustomer()->withCompleteProfile()->create();

    expect(Address::ofCustomer($user->customer)->count())->toBe(0);

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit(new Addresses)
            ->openAddressForm()
            ->disableFormValidation()
            ->press('@saveAddress')
            ->waitForText('El campo calle es requerido.')
            ->waitForText('El campo número es requerido.')
            ->waitForText('El campo colonia es requerido.')
            ->waitForText('El campo estado es requerido.')
            ->waitForText('El campo ciudad o municipio es requerido.')
            ->waitForText('El campo código postal es requerido.');
    });

    expect(Address::ofCustomer($user->customer)->count())->toBe(0);
});

test('user can update address', function () {
    $user = User::factory()->withRegularCustomer()->withCompleteProfile()->create();

    $address = Address::factory()->for($user->customer)->create();

    expect(Address::ofCustomer($user->customer)->count())->toBe(1);

    $addressData = Address::factory()->make([
        'customer_id' => $user->customer->id,
    ]);

    $this->browse(function (Browser $browser) use ($user, $address, $addressData) {
        $browser->loginAs($user)
            ->visit(new Addresses)
            ->openAddressForm($address)
            ->clearAddress()
            ->typeAddress(
                street: $addressData->street,
                number: $addressData->number,
                neighborhood: $addressData->neighborhood,
                state: $addressData->state,
                city: $addressData->city,
                zipcode: $addressData->zipcode,
                additionalReferences: $addressData->additional_references,
            )
            ->waitForText('Dirección actualizada exitosamente.');
    });

    expect(Address::ofCustomer($user->customer)->count())->toBe(1);
    $address = $user->customer->addresses()->first();

    expect($address->street)->toBe($addressData->street);
    expect($address->number)->toBe($addressData->number);
    expect($address->neighborhood)->toBe($addressData->neighborhood);
    expect($address->state)->toBe($addressData->state);
    expect($address->city)->toBe($addressData->city);
    expect($address->zipcode)->toBe($addressData->zipcode);
    expect($address->additional_references)->toBe($addressData->additional_references);
});

test('user cannot update address with empty values', function () {
    $user = User::factory()->withRegularCustomer()->withCompleteProfile()->create();

    $address = Address::factory()->for($user->customer)->create();

    expect(Address::ofCustomer($user->customer)->count())->toBe(1);

    $this->browse(function (Browser $browser) use ($user, $address) {
        $browser->loginAs($user)
            ->visit(new Addresses)
            ->openAddressForm($address)
            ->disableFormValidation()
            ->clearAddress()
            ->press('@saveAddress')
            ->waitForText('El campo calle es requerido.')
            ->waitForText('El campo número es requerido.')
            ->waitForText('El campo colonia es requerido.')
            ->waitForText('El campo código postal es requerido.');
    });
});

test('user can delete address', function () {
    $user = User::factory()->withRegularCustomer()->withCompleteProfile()->create();

    Address::factory()->for($user->customer)->create();

    expect(Address::ofCustomer($user->customer)->count())->toBe(1);

    $address = $user->customer->addresses()->first();

    $this->browse(function (Browser $browser) use ($user, $address) {
        $browser->loginAs($user)
            ->visit(new Addresses)
            ->deleteAddress($address)
            ->waitForText('Dirección eliminada exitosamente.');
    });

    expect(Address::ofCustomer($user->customer)->count())->toBe(0);
});
