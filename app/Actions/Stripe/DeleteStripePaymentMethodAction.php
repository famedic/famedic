<?php

namespace App\Actions\Stripe;

use App\Models\Customer;

class DeleteStripePaymentMethodAction
{
    public function __invoke(Customer $customer, string $paymentMethodId)
    {
        $paymentMethod = $customer->findPaymentMethod($paymentMethodId);

        if ($paymentMethod) {
            $customer->deletePaymentMethod($paymentMethod->id);
        }
    }
}
