<?php

use App\Actions\GeneratePhoneNumberAction;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\Browser\Pages\Account;

test('user can update basic info', function () {
    $user = User::factory()->create();
    $userData = User::factory()->make();

    $this->browse(function (Browser $browser) use ($user, $userData) {
        $browser->loginAs($user)
            ->visit(new Account)
            ->clearBasicInfo()
            ->updateBasicInfo(
                $userData->name,
                $userData->paternal_lastname,
                $userData->maternal_lastname,
                $userData->birth_date,
                $userData->gender
            )

            ->waitForText('Tu información básica ha sido actualizada.');
    });

    $user->refresh();

    expect($user->name)->toBe($userData->name);
    expect($user->paternal_lastname)->toBe($userData->paternal_lastname);
    expect($user->maternal_lastname)->toBe($userData->maternal_lastname);
    expect($user->birth_date->format('Y-m-d'))->toBe($userData->birth_date->format('Y-m-d'));
    expect($user->gender->value)->toBe($userData->gender->value);
});

test('user can not update basic info with empty values', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit(new Account)
            ->disableFormValidation()
            ->clearBasicInfo()
            ->press('@updateBasicInfo')
            ->waitForText('El campo nombre es requerido.')
            ->waitForText('El campo apellido paterno es requerido.')
            ->waitForText('El campo apellido materno es requerido.')
            ->waitForText('El campo fecha de nacimiento es requerido.');
    });
});

test('user can not update basic info with current date as birth date', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit(new Account)
            ->clearDateInput('@birthDate')
            ->inputDate('@birthDate', now())
            ->press('@updateBasicInfo')
            ->waitForText('El campo fecha de nacimiento debe ser una fecha antes de hoy.');
    });
});

test('user can update contact info', function () {
    $user = User::factory()
        ->create();

    $phone = app(GeneratePhoneNumberAction::class)('MX');

    $userData = User::factory()
        ->withVerifiedPhone($phone)
        ->make();

    $this->browse(function (Browser $browser) use ($user, $userData) {
        $browser->loginAs($user)
            ->visit(new Account)
            ->clearContactInfo()
            ->updateContactInfo(
                $userData->phone->getRawNumber(),
                $userData->email
            )
            ->waitForText('Tu información de contacto ha sido actualizada.')
            ->waitForText('Tu correo electrónico debe verificarse.')
            ->waitForText('Tu teléfono celular debe verificarse.');
    });

    $user->refresh();

    expect($user->email)->toBe($userData->email);
    expect($user->phone->getRawNumber())->toBe($userData->phone->getRawNumber());
    expect($user->email_verified_at)->toBeNull();
    expect($user->phone_verified_at)->toBeNull();
});

test('user can request verification email', function () {
    $user = User::factory()->withUnverifiedEmail()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit(new Account)
            ->waitForText('Tu correo electrónico debe verificarse.')
            ->click('@emailVerify')
            ->waitForText('Se ha enviado un enlace de verificación a tu dirección de correo electrónico.');
    });
});

test('user can not update contact info with empty values', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit(new Account)
            ->disableFormValidation()
            ->clearContactInfo()
            ->press('@updateContactInfo')
            ->waitForText('El campo teléfono celular es requerido.')
            ->waitForText('El campo correo electrónico es requerido.');
    });
});

test('user can not update contact info with existing email and phone', function () {
    $user = User::factory()->create();
    $phone = app(GeneratePhoneNumberAction::class)('MX');
    $existingUser = User::factory()
        ->withVerifiedPhone($phone)
        ->create();

    $this->browse(function (Browser $browser) use ($user, $existingUser) {
        $browser->loginAs($user)
            ->visit(new Account)
            ->clearContactInfo()
            ->updateContactInfo(
                $existingUser->phone->getRawNumber(),
                $existingUser->email
            )
            ->waitForText('El campo teléfono celular ya ha sido registrado.')
            ->waitForText('El campo correo electrónico ya ha sido registrado.');
    });
});

test('user can not update contact info with invalid email', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit(new Account)
            ->disableFormValidation()
            ->clearInput('@email')
            ->type('@email', 'invalid-email')
            ->press('@updateContactInfo')
            ->waitForText('El campo correo electrónico debe ser una dirección de correo electrónico válida.');
    });
});

test('user can update password', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit(new Account)
            ->updatePassword(
                'password',
                'new-password',
                'new-password'
            )
            ->waitForText('Contraseña actualizada con éxito.');
    });

    $user->refresh();

    expect(Hash::check('new-password', $user->password))->toBeTrue();
});

test('user can not update password with empty values', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit(new Account)
            ->disableFormValidation()
            ->clearPassword()
            ->press('@updatePassword')
            ->waitForText('El campo contraseña actual es requerido.')
            ->waitForText('El campo contraseña es requerido.');
    });
});

test('user can not update password with invalid current password', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit(new Account)
            ->updatePassword(
                'invalid-password',
                'new-password',
                'new-password'
            )
            ->waitForText('La contraseña es incorrecta.');
    });
});

test('user can not update password with different password and password confirmation', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit(new Account)
            ->updatePassword(
                'password',
                'new-password',
                'different-password'
            )
            ->waitForText('La confirmación del campo contraseña no coincide.');
    });
});
