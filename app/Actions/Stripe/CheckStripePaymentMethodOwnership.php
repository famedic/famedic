<?php

namespace App\Actions\Stripe;

use App\Exceptions\InvalidPaymentMethodException;
use App\Models\Customer;

class CheckStripePaymentMethodOwnership
{
    public function __invoke(Customer $customer, string $paymentMethod)
    {
        $allowedPaymentMethods = [];

        $customer->paymentMethods()->each(function ($paymentMethod) use (&$allowedPaymentMethods) {
            $allowedPaymentMethods[] = $paymentMethod->id;
        });

        if (!in_array($paymentMethod, $allowedPaymentMethods)) {
            throw new InvalidPaymentMethodException();
        }
    }
}
