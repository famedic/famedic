<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;
use Tests\Browser\Pages\ConfirmPassword;
use Tests\Browser\Pages\ForgotPassword;
use Tests\Browser\Pages\ResetPassword;

test('user can request password reset link', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->visit(new ForgotPassword)
            ->requestPasswordResetLink($user->email)
            ->waitForText('Hemos enviado por correo electrónico su enlace de restablecimiento de contraseña.');
    });
});

test('user can not request password reset link with empty email', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit(new ForgotPassword)
            ->disableFormValidation()
            ->press('@requestPasswordResetLink')
            ->waitForText('El campo correo electrónico es requerido.');
    });
});

test('user can not request password reset link with invalid email', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit(new ForgotPassword)
            ->disableFormValidation()
            ->requestPasswordResetLink('invalid-email')
            ->waitForText('El campo correo electrónico debe ser una dirección de correo electrónico válida.');
    });
});

test('user can not request password reset link with non-existent email', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit(new ForgotPassword)
            ->requestPasswordResetLink('inexistent-email@example.com')
            ->waitForText('No podemos encontrar un usuario con esa dirección de correo electrónico.');
    });
});

test('user can reset password with reset link', function () {
    $resetPassword = new ResetPassword;
    $this->browse(function (Browser $browser) use ($resetPassword) {
        $browser->visit($resetPassword)
            ->resetPassword('new-password', 'new-password')
            ->waitForText('Su contraseña ha sido restablecida.');
    });

    $resetPassword->user->refresh();

    expect(Hash::check('new-password', $resetPassword->user->password))->toBeTrue();
});

test('user can not reset password with empty values', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit(new ResetPassword)
            ->disableFormValidation()
            ->press('@resetPassword')
            ->waitForText('El campo contraseña es requerido.');
    });
});

test('user can not reset password with invalid email', function () {
    $user = User::factory()->create();
    $this->browse(function (Browser $browser) use ($user) {
        $browser->visit(new ResetPassword)
            ->clearInput('@email')
            ->type('@email', $user->email)
            ->resetPassword('new-password', 'new-password')
            ->waitForText('Este token de restablecimiento de contraseña es inválido.');
    });
});

test('user can not reset password with invalid password confirmation', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit(new ResetPassword)
            ->resetPassword('new-password', 'invalid-password-confirmation')
            ->waitForText('La confirmación del campo contraseña no coincide.');
    });
});

test('user can confirm password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit(new ConfirmPassword)
            ->confirmPassword('password')
            ->waitForText('Contraseña confirmada satisfactoriamente.');
    });
});

test('user can not confirm password with empty password', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit(new ConfirmPassword)
            ->disableFormValidation()
            ->press('@confirmPassword')
            ->waitForText('El campo contraseña es requerido.');
    });
});

test('user can not confirm password with invalid password', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit(new ConfirmPassword)
            ->confirmPassword('invalid-password')
            ->waitForText('La contraseña proporcionada es incorrecta.');
    });
});
