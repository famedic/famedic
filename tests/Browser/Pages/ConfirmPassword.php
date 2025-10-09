<?php

namespace Tests\Browser\Pages;

use Laravel\Dusk\Browser;
use Laravel\Dusk\Page;

class ConfirmPassword extends Page
{
    /**
     * Get the URL for the page.
     */
    public function url(): string
    {
        return '/confirm-password';
    }

    public function assert(Browser $browser): void
    {
        $browser->assertPathIs($this->url())->assertSee('Confirmar contraseña');
    }

    public function confirmPassword(Browser $browser, string $password): void
    {
        $browser
            ->type('@password', $password)
            ->press('@confirmPassword');
    }
}
