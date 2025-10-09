<?php

namespace Tests\Browser\Pages;

use App\Models\User;
use Laravel\Cashier\PaymentMethod;
use Laravel\Dusk\Browser;
use Laravel\Dusk\Page;

class PaymentMethods extends Page
{
    public function url(): string
    {
        return '/payment-methods';
    }

    public function assert(Browser $browser): void
    {
        $browser->assertPathIs($this->url())->assertSee('Mis métodos de pago');
    }

    public function openPaymentMethodDeleteConfirmation(Browser $browser, PaymentMethod $paymentMethod): void
    {
        $browser->click("@deletePaymentMethod-{$paymentMethod->id}")
            ->waitForText('Eliminar tarjeta "'.$paymentMethod->card->brand.' '.$paymentMethod->card->last4.'"');
    }

    public function createPaymentMethod(Browser $browser, User $user): void
    {
        $browser->press('@createPaymentMethod')
            ->waitForText('Introduce los datos del pago', 10)
            ->type('cardNumber', '4242424242424242')
            ->type('cardExpiry', '12/'.now()->addYear()->format('y'))
            ->type('cardCvc', '123')
            ->type('billingName', $user->full_name)
            ->press('Guardar tarjeta')
            ->waitForText('Tarjeta guardada exitosamente', 10);
    }
}
