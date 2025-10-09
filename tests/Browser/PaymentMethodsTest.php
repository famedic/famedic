<?php

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\Browser\Pages\PaymentMethods;

test('user can add card as payment method', function () {
    $user = User::factory()->withRegularCustomer()->withCompleteProfile()->create();

    expect($user->customer->paymentMethods()->count())->toBe(0);

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit(new PaymentMethods)
            ->createPaymentMethod($user);
    });

    $customer = $user->customer->fresh();
    expect($customer->paymentMethods()->count())->toBe(1);
    expect($customer->paymentMethods()->first()->card->last4)->toBe('4242');
    expect($customer->paymentMethods()->first()->card->exp_month)->toBe(12);
    expect((string) $customer->paymentMethods()->first()->card->exp_year)->toBe(now()->addYear()->format('Y'));
    expect($customer->paymentMethods()->first()->card->brand)->toBe('visa');
});

test('user can delete payment method', function () {
    $user = User::factory()->withRegularCustomer()->withCompleteProfile()->create();

    expect($user->customer->paymentMethods()->count())->toBe(0);

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit(new PaymentMethods)
            ->createPaymentMethod($user);
    });

    $customer = $user->customer->fresh();
    $paymentMethod = $customer->paymentMethods()->sole();

    $this->browse(function (Browser $browser) use ($user, $paymentMethod) {
        $browser->loginAs($user)
            ->visit(new PaymentMethods)
            ->openPaymentMethodDeleteConfirmation($paymentMethod)
            ->press('@delete')
            ->waitForText('Tarjeta eliminada exitosamente');
    });

    expect($user->customer->paymentMethods()->count())->toBe(0);
});
