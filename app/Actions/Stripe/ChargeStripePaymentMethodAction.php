<?php

namespace App\Actions\Stripe;

use App\Models\Customer;
use App\Models\Transaction;
use Stripe\PaymentMethod;
use Stripe\Stripe;

class ChargeStripePaymentMethodAction
{
    private CheckStripePaymentMethodOwnership $checkStripePaymentMethodOwnership;

    public function __construct(CheckStripePaymentMethodOwnership $checkStripePaymentMethodOwnership)
    {
        $this->checkStripePaymentMethodOwnership = $checkStripePaymentMethodOwnership;
    }

    public function __invoke(Customer $customer, int $amountCents, string $paymentMethod): Transaction
    {
        ($this->checkStripePaymentMethodOwnership)($customer, $paymentMethod);

        $charge = $customer->charge(
            $amountCents,
            $paymentMethod,
            [
                'currency' => 'mxn',
                'off_session' => true,
                'confirm' => true,
            ]
        );

        Stripe::setApiKey(config('services.stripe.secret'));
        $stripePaymentMethod = PaymentMethod::retrieve($charge->payment_method);

        $details = [
            'card_brand' => $stripePaymentMethod->card->brand,
            'card_last_four' => $stripePaymentMethod->card->last4,
            'payment_method_id' => $stripePaymentMethod->id,
        ];

        $transaction = Transaction::create([
            'transaction_amount_cents' => $amountCents,
            'payment_method' => 'stripe',
            'reference_id' => $charge->id,
            'details' => $details,
        ]);

        return $transaction;
    }
}
