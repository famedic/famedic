<?php

namespace Tests\Browser\Pages;

use App\Enums\Gender;
use Carbon\Carbon;
use Laravel\Dusk\Browser;
use Laravel\Dusk\Page;

class Account extends Page
{
    public function url(): string
    {
        return '/user';
    }

    public function assert(Browser $browser): void
    {
        $browser->assertPathIs($this->url())->assertSee('Mi cuenta');
    }

    public function updateBasicInfo(Browser $browser, string $name, string $paternalLastname, string $maternalLastname, string $birthDate, Gender $gender): void
    {
        $browser
            ->type('@name', $name)
            ->type('@paternalLastname', $paternalLastname)
            ->type('@maternalLastname', $maternalLastname)
            ->inputDate('@birthDate', Carbon::parse($birthDate))
            ->select('@gender', $gender->value)
            ->press('@updateBasicInfo');
    }

    public function openContactTab(Browser $browser): void
    {
        $browser->click('@accountTab-contacto');
    }

    public function openPasswordTab(Browser $browser): void
    {
        $browser->click('@accountTab-seguridad');
    }

    public function updateContactInfo(Browser $browser, string $phone, string $email): void
    {
        $browser
            ->click('@accountTab-contacto')
            ->type('@phone', $phone)
            ->type('@email', $email)
            ->press('@updateContactInfo');
    }

    public function updatePassword(Browser $browser, string $currentPassword, string $password, string $passwordConfirmation): void
    {
        $browser
            ->click('@accountTab-seguridad')
            ->type('@currentPassword', $currentPassword)
            ->type('@password', $password)
            ->type('@passwordConfirmation', $passwordConfirmation)
            ->press('@updatePassword');
    }

    public function clearBasicInfo(Browser $browser): void
    {
        $browser->clearInput('@name')
            ->clearInput('@paternalLastname')
            ->clearInput('@maternalLastname')
            ->clearDateInput('@birthDate');
    }

    public function clearContactInfo(Browser $browser): void
    {
        $browser->click('@accountTab-contacto')
            ->clearInput('@phone')
            ->clearInput('@email');
    }

    public function clearPassword(Browser $browser): void
    {
        $browser->click('@accountTab-seguridad')
            ->clearInput('@currentPassword')
            ->clearInput('@password')
            ->clearInput('@passwordConfirmation');
    }
}
