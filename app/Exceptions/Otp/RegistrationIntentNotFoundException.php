<?php

namespace App\Exceptions\Otp;

class RegistrationIntentNotFoundException extends RegistrationIntentException
{
    public function __construct(string $message = 'El intent de registro no existe.')
    {
        parent::__construct($message, 'REGISTRATION_INTENT_NOT_FOUND');
    }
}
