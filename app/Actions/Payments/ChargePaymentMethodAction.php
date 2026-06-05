<?php

namespace App\Actions\Payments;

use App\Models\Customer;
use App\Models\Transaction;
use App\Services\Payments\PaymentGatewayManager;

class ChargePaymentMethodAction
{
    public function __construct(
        private PaymentGatewayManager $gatewayManager,
    ) {}

    public function __invoke(
        Customer $customer,
        int $amountCents,
        string $paymentMethodId,
        ?string $reference = null,
    ): Transaction {
        $gateway = $this->gatewayManager->forPaymentMethodId($paymentMethodId);

        return $gateway->charge($customer, $amountCents, $paymentMethodId, $reference);
    }
}
