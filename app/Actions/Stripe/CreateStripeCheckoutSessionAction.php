<?php

namespace App\Actions\Stripe;

use Stripe\Checkout\Session;
use Stripe\Customer as StripeCustomer;
use Stripe\Stripe;

class CreateStripeCheckoutSessionAction
{
    public function __invoke(StripeCustomer $stripeCustomer, string $successURL, string $cancelURL): Session
    {
        Stripe::setApiKey(config('services.stripe.secret'));
        return Session::create([
            'payment_method_types' => ['card'],
            'mode' => 'setup',
            'customer' => $stripeCustomer->id,
            'locale' => 'es',
            'success_url' => $successURL,
            'cancel_url' => $cancelURL,
        ]);
    }
}
