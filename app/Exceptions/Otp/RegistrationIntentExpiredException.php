<?php

namespace App\Exceptions\Otp;

class RegistrationIntentExpiredException extends RegistrationIntentException
{
    public function __construct(string $message = 'El intent de registro ha expirado.')
    {
        parent::__construct($message, 'REGISTRATION_INTENT_EXPIRED');
    }
}
