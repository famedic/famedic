<?php

namespace App\Exceptions;

use App\Models\LaboratoryPurchase;
use RuntimeException;

class LaboratoryPaymentAlreadyReceivedException extends RuntimeException
{
    public function __construct(
        private readonly LaboratoryPurchase $purchase,
        string $message = 'Tu pago ya fue recibido. Estamos validando la creación de tu orden de laboratorio.',
    ) {
        parent::__construct($message);
    }

    public function purchase(): LaboratoryPurchase
    {
        return $this->purchase;
    }
}
