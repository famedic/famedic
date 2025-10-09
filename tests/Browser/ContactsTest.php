<?php

use App\Actions\GeneratePhoneNumberAction;
use App\Models\Contact;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\Browser\Pages\Contacts;

test('user can create contact', function () {
    $user = User::factory()->withRegularCustomer()->withCompleteProfile()->create();

    $phone = app(GeneratePhoneNumberAction::class)('MX');
    $contactData = Contact::factory()->make([
        'customer_id' => $user->customer->id,
        'phone' => str_replace(' ', '', $phone->getRawNumber()),
        'phone_country' => $phone->getCountry(),
    ]);

    expect(Contact::ofCustomer($user->customer)->count())->toBe(0);

    $this->browse(function (Browser $browser) use ($user, $contactData) {
        $browser->loginAs($user)
            ->visit(new Contacts)
            ->openContactForm()
            ->typeContact(
                name: $contactData->name,
                paternalLastname: $contactData->paternal_lastname,
                maternalLastname: $contactData->maternal_lastname,
                phone: $contactData->phone->getRawNumber(),
                birthDate: $contactData->birth_date,
                gender: $contactData->gender,
            )
            ->waitForText('Contacto guardado exitosamente.');
    });

    expect(Contact::ofCustomer($user->customer)->count())->toBe(1);
    $contact = $user->customer->contacts()->first();

    expect($contact->name)->toBe($contactData->name);
    expect($contact->paternal_lastname)->toBe($contactData->paternal_lastname);
    expect($contact->maternal_lastname)->toBe($contactData->maternal_lastname);
    expect($contact->phone->getRawNumber())->toBe($contactData->phone->getRawNumber());
    expect($contact->birth_date->format('Y-m-d'))->toBe($contactData->birth_date->format('Y-m-d'));
    expect($contact->gender->value)->toBe($contactData->gender->value);
});

test('user cannot create contact with empty values', function () {
    $user = User::factory()->withRegularCustomer()->withCompleteProfile()->create();

    expect(Contact::ofCustomer($user->customer)->count())->toBe(0);

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit(new Contacts)
            ->openContactForm()
            ->disableFormValidation()
            ->press('@saveContact')
            ->waitForText('El campo nombre es requerido.')
            ->waitForText('El campo apellido paterno es requerido.')
            ->waitForText('El campo apellido materno es requerido.')
            ->waitForText('El campo teléfono celular es requerido.')
            ->waitForText('El campo fecha de nacimiento es requerido.')
            ->waitForText('El campo sexo es requerido.');
    });

    expect(Contact::ofCustomer($user->customer)->count())->toBe(0);
});

test('user can update contact', function () {
    $user = User::factory()->withRegularCustomer()->withCompleteProfile()->create();

    $phone = app(GeneratePhoneNumberAction::class)('MX');
    $contact = Contact::factory()->for($user->customer)->create([
        'phone' => str_replace(' ', '', $phone->getRawNumber()),
        'phone_country' => $phone->getCountry(),
    ]);

    expect(Contact::ofCustomer($user->customer)->count())->toBe(1);

    $phone = app(GeneratePhoneNumberAction::class)('MX');
    $contactData = Contact::factory()->make([
        'customer_id' => $user->customer->id,
        'phone' => str_replace(' ', '', $phone->getRawNumber()),
        'phone_country' => $phone->getCountry(),
    ]);

    $this->browse(function (Browser $browser) use ($user, $contact, $contactData) {
        $browser->loginAs($user)
            ->visit(new Contacts)
            ->openContactForm($contact)
            ->clearContact()
            ->typeContact(
                name: $contactData->name,
                paternalLastname: $contactData->paternal_lastname,
                maternalLastname: $contactData->maternal_lastname,
                phone: $contactData->phone->getRawNumber(),
                birthDate: $contactData->birth_date,
                gender: $contactData->gender,
            )
            ->waitForText('Contacto actualizado exitosamente.');
    });

    expect(Contact::ofCustomer($user->customer)->count())->toBe(1);
    $contact = $user->customer->contacts()->first();

    expect($contact->name)->toBe($contactData->name);
    expect($contact->paternal_lastname)->toBe($contactData->paternal_lastname);
    expect($contact->maternal_lastname)->toBe($contactData->maternal_lastname);
    expect($contact->phone->getRawNumber())->toBe($contactData->phone->getRawNumber());
    expect($contact->birth_date->format('Y-m-d'))->toBe($contactData->birth_date->format('Y-m-d'));
    expect($contact->gender->value)->toBe($contactData->gender->value);
});

test('user cannot update contact with empty values', function () {
    $user = User::factory()->withRegularCustomer()->withCompleteProfile()->create();

    $contact = Contact::factory()->for($user->customer)->create();

    expect(Contact::ofCustomer($user->customer)->count())->toBe(1);

    $this->browse(function (Browser $browser) use ($user, $contact) {
        $browser->loginAs($user)
            ->visit(new Contacts)
            ->openContactForm($contact)
            ->disableFormValidation()
            ->clearContact()
            ->press('@saveContact')
            ->waitForText('El campo nombre es requerido.')
            ->waitForText('El campo apellido paterno es requerido.')
            ->waitForText('El campo apellido materno es requerido.')
            ->waitForText('El campo teléfono celular es requerido.')
            ->waitForText('El campo fecha de nacimiento es requerido.');
    });

    expect(Contact::ofCustomer($user->customer)->count())->toBe(1);
});

test('user can delete contact', function () {
    $user = User::factory()->withRegularCustomer()->withCompleteProfile()->create();

    $contact = Contact::factory()->for($user->customer)->create();

    expect(Contact::ofCustomer($user->customer)->count())->toBe(1);

    $this->browse(function (Browser $browser) use ($user, $contact) {
        $browser->loginAs($user)
            ->visit(new Contacts)
            ->deleteContact($contact)
            ->waitForText('Contacto eliminado exitosamente.');
    });

    expect(Contact::ofCustomer($user->customer)->count())->toBe(0);
});
