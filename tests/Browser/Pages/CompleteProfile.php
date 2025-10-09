<?php

namespace Tests\Browser\Pages;

use Laravel\Dusk\Browser;
use Laravel\Dusk\Page;

class CompleteProfile extends Page
{
    public function url(): string
    {
        return '/complete-profile';
    }

    public function assert(Browser $browser): void
    {
        $browser->assertPathIs($this->url())->assertSee('Completa tu perfil');
    }

    public function completePhone(Browser $browser, string $phone): void
    {
        $browser->type('@phone', $phone)
            ->press('@completeProfile');
    }
}
