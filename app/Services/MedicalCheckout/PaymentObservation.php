<?php

namespace App\Services\MedicalCheckout;

/** Internal typed observation. Never constructed from a client request or Transaction.status. */
final readonly class PaymentObservation
{
    public function __construct(public string $outcome, public string $reference, public int $cashCents, public string $event = '', public string $currency = 'MXN', public ?string $providerOrder = null) {}
}
