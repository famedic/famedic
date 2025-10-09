<?php

use App\Actions\GeneratePhoneNumberAction;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\Browser\Pages\Register;

test('user can register', function () {
    $phone = app(GeneratePhoneNumberAction::class)('MX');

    $userData = User::factory()
        ->withVerifiedPhone($phone)
        ->make();

    $this->browse(function (Browser $browser) use ($userData) {
        $browser->visit(new Register)
            ->register(
                $userData->name,
                $userData->paternal_lastname,
                $userData->maternal_lastname,
                $userData->email,
                $userData->phone->getRawNumber(),
                $userData->birth_date,
                $userData->gender,
                'password',
                'password'
            )
            ->waitForRoute('verification.notice');
    });

    $user = User::where('email', $userData->email)->first();

    expect($user->name)->toBe($userData->name);
    expect($user->paternal_lastname)->toBe($userData->paternal_lastname);
    expect($user->maternal_lastname)->toBe($userData->maternal_lastname);
    expect($user->email)->toBe($userData->email);
    expect($user->phone->getRawNumber())->toBe($userData->phone->getRawNumber());
    expect($user->birth_date->format('Y-m-d'))->toBe($userData->birth_date->format('Y-m-d'));
    expect($user->gender->value)->toBe($userData->gender->value);
});

test('user can not register with existing email and phone', function () {
    $phone = app(GeneratePhoneNumberAction::class)('MX');

    $userData = User::factory()
        ->withVerifiedPhone($phone)
        ->make();

    User::factory()->withVerifiedPhone(
        $userData->phone
    )->create([
        'email' => $userData->email,
    ]);

    $this->browse(function (Browser $browser) use ($userData) {
        $browser->visit(new Register)
            ->register(
                $userData->name,
                $userData->paternal_lastname,
                $userData->maternal_lastname,
                $userData->email,
                $userData->phone->getRawNumber(),
                $userData->birth_date,
                $userData->gender,
                'password',
                'password'
            )
            ->waitForText('El campo correo electrónico ya ha sido registrado.')
            ->waitForText('El campo teléfono celular ya ha sido registrado.');
    });
});

test('user can not register with invalid email', function () {
    $userData = User::factory()
        ->withVerifiedPhone()
        ->make();
    $userData->email = 'invalid-email';

    $this->browse(function (Browser $browser) use ($userData) {
        $browser->visit(new Register)
            ->disableFormValidation()
            ->register(
                $userData->name,
                $userData->paternal_lastname,
                $userData->maternal_lastname,
                $userData->email,
                $userData->phone->getRawNumber(),
                $userData->birth_date,
                $userData->gender,
                'password',
                'password'
            )
            ->waitForText('El campo correo electrónico debe ser una dirección de correo electrónico válida.');
    });
});

test('user can not register with current date as birth date', function () {
    $userData = User::factory()
        ->withVerifiedPhone()
        ->make();
    $userData->birth_date = now()->format('Y-m-d');

    $this->browse(function (Browser $browser) use ($userData) {
        $browser->visit(new Register)
            ->register(
                $userData->name,
                $userData->paternal_lastname,
                $userData->maternal_lastname,
                $userData->email,
                $userData->phone->getRawNumber(),
                $userData->birth_date,
                $userData->gender,
                'password',
                'password'
            )
            ->waitForText('El campo fecha de nacimiento debe ser una fecha antes de hoy.');
    });
});

test('user can not register with empty values', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit(new Register)
            ->disableFormValidation()
            ->press('@register')
            ->waitForText('El campo nombre es requerido.')
            ->waitForText('El campo apellido paterno es requerido.')
            ->waitForText('El campo apellido materno es requerido.')
            ->waitForText('El campo correo electrónico es requerido.')
            ->waitForText('El campo teléfono celular es requerido.')
            ->waitForText('El campo sexo es requerido.')
            ->waitForText('El campo fecha de nacimiento es requerido.')
            ->waitForText('El campo contraseña es requerido.');
    });
});
