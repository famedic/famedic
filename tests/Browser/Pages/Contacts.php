<?php

namespace Tests\Browser\Pages;

use App\Enums\Gender;
use App\Models\Contact;
use Carbon\Carbon;
use Laravel\Dusk\Browser;
use Laravel\Dusk\Page;

class Contacts extends Page
{
    public function url(): string
    {
        return '/contacts';
    }

    public function assert(Browser $browser): void
    {
        $browser->assertPathIs($this->url())->assertSee('Mis pacientes frecuentes');
    }

    public function openContactForm(Browser $browser, ?Contact $contact = null): void
    {
        if ($contact) {
            $browser->click("@editContact-{$contact->id}")
                ->waitForText('Edita tu paciente');
        } else {
            $browser->click('@createContact')
                ->waitForText('Agregar paciente frecuente');
        }
    }

    public function openContactDeleteConfirmation(Browser $browser, Contact $contact): void
    {
        $browser->click("@deleteContact-{$contact->id}")
            ->waitForText('Eliminar paciente "'.$contact->full_name.'"');
    }

    public function typeContact(Browser $browser, string $name, string $paternalLastname, string $maternalLastname, string $phone, string $birthDate, Gender $gender): void
    {
        $browser->type('@name', $name)
            ->type('@paternalLastname', $paternalLastname)
            ->type('@maternalLastname', $maternalLastname)
            ->type('@phone', $phone)
            ->inputDate('@birthDate', Carbon::parse($birthDate))
            ->select('@gender', $gender->value)
            ->press('@saveContact');
    }

    public function clearContact(Browser $browser): void
    {
        $browser->clearInput('@name')
            ->clearInput('@paternalLastname')
            ->clearInput('@maternalLastname')
            ->clearInput('@phone')
            ->clearDateInput('@birthDate');
    }

    public function deleteContact(Browser $browser, Contact $contact): void
    {
        $browser->openContactDeleteConfirmation($contact)
            ->press('@delete');
    }
}
