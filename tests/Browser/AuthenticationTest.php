<?php

use App\Actions\GeneratePhoneNumberAction;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\Browser\Pages\CompleteProfile;
use Tests\Browser\Pages\Home;
use Tests\Browser\Pages\Login;

test('complete user can authenticate', function () {
    $user = User::factory()
        ->withCompleteProfile()
        ->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->visit(new Login)
            ->loginWithCredentials($user->email, 'password')
            ->waitForRoute('home')
            ->assertRouteIs('home')
            ->waitForText('¡Bienvenido a Famedic!');
    });
});

test('user with incomplete profile gets redirected', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->visit(new Login)
            ->loginWithCredentials($user->email, 'password')
            ->waitForRoute('complete-profile')
            ->waitForText('¡Bienvenido a Famedic!')
            ->waitForText('Completa tu perfil');
    });
});

test('user with complete profile gets redirected', function () {
    $user = User::factory()
        ->withCompleteProfile()
        ->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/complete-profile')
            ->waitForRoute('home');
    });
});

test('user can complete profile', function () {
    $user = User::factory()
        ->withVerifiedEmail()
        ->create();

    $phone = app(GeneratePhoneNumberAction::class)('MX');

    $this->browse(function (Browser $browser) use ($user, $phone) {
        $browser->loginAs($user)
            ->visit(new CompleteProfile)
            ->completePhone($phone->getRawNumber())
            ->waitForRoute('phone.verification.notice')
            ->waitForText('Tu perfil está completo.');
    });
});

test('user can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->visit(new Login)
            ->loginWithCredentials($user->email, 'invalid-password')
            ->waitForText('Estas credenciales no coinciden con nuestros registros.')
            ->assertSee('Estas credenciales no coinciden con nuestros registros.');
    });
});

test('user can not authenticate with empty values', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit(new Login)
            ->script('document.querySelector("form").noValidate = true;');

        $browser->press('@login')
            ->waitForText('El campo correo electrónico es requerido.')
            ->waitForText('El campo contraseña es requerido.');
    });
});

test('user can logout', function () {
    $user = User::factory()
        ->withCompleteProfile()
        ->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit(new Home)
            ->pressLogoutButton()
            ->waitForRoute('welcome')
            ->assertRouteIs('welcome');
    });
});
