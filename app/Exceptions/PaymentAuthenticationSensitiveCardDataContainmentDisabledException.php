<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentAuthenticationSensitiveCardDataContainmentDisabledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct((string) config(
            'efevoopay.sensitive_card_data.messages.containment_disabled',
            'La verificación con tarjeta no está disponible temporalmente.'
        ));
    }
}
