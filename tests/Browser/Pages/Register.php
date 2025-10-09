<?php

namespace Tests\Browser\Pages;

use App\Enums\Gender;
use Carbon\Carbon;
use Laravel\Dusk\Browser;
use Laravel\Dusk\Page;

class Register extends Page
{
    public function url(): string
    {
        return '/register';
    }

    public function assert(Browser $browser): void
    {
        $browser->assertPathIs($this->url());
    }

    public function register(Browser $browser, string $name, string $paternalLastName, string $maternalLastName, string $email, string $phone, string $birthDate, Gender $gender, string $password, string $confirmPassword): void
    {
        $browser->type('@name', $name)
            ->type('@paternalLastname', $paternalLastName)
            ->type('@maternalLastname', $maternalLastName)
            ->type('@email', $email)
            ->type('@phone', $phone)
            ->inputDate('@birthDate', Carbon::parse($birthDate))
            ->select('@gender', $gender->value)
            ->type('@password', $password)
            ->type('@passwordConfirmation', $confirmPassword)
            ->press('@register');
    }
}
