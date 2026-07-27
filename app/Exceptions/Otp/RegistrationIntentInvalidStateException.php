<?php

namespace App\Exceptions\Otp;

class RegistrationIntentInvalidStateException extends RegistrationIntentException
{
    public function __construct(string $message = 'El intent de registro no admite esta operacion.')
    {
        parent::__construct($message, 'REGISTRATION_INTENT_INVALID_STATE');
    }
}
