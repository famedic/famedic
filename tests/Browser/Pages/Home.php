<?php

namespace Tests\Browser\Pages;

use Laravel\Dusk\Browser;
use Laravel\Dusk\Page;

class Home extends Page
{
    public function url(): string
    {
        return '/home';
    }

    public function assert(Browser $browser): void
    {
        $browser->assertPathIs($this->url())->assertSee('Bienvenido a tu espacio de salud y bienestar');
    }

    public function pressLogoutButton(Browser $browser): void
    {
        $browser->waitFor('@userNavigation')
            ->press('@userNavigation')
            ->waitFor('@logout')
            ->press('@logout');
    }
}
