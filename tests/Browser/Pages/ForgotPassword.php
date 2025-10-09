<?php

namespace Tests\Browser\Pages;

use Laravel\Dusk\Browser;
use Laravel\Dusk\Page;

class ForgotPassword extends Page
{
    public function url(): string
    {
        return '/forgot-password';
    }

    public function assert(Browser $browser): void
    {
        $browser->assertPathIs($this->url())->assertSee('¿Olvidaste tu contraseña?');
    }

    public function requestPasswordResetLink(Browser $browser, string $email): void
    {
        $browser
            ->type('@email', $email)
            ->press('@requestPasswordResetLink');
    }
}
