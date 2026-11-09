<?php

namespace App\Services\MedicalCheckout;

use App\Models\Customer;
use App\Models\Transaction;

interface MedicalRemnantGateway
{
    public function start(Customer $customer, Transaction $transaction, string $reference, int $cash, string $paymentMethod): PaymentObservation;

    public function observe(Customer $customer, Transaction $transaction, string $reference, int $cash): PaymentObservation;

    public function reverse(Customer $customer, Transaction $transaction, string $reference, int $cash): PaymentObservation;
}
