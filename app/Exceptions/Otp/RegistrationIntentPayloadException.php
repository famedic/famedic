<?php

namespace App\Exceptions\Otp;

class RegistrationIntentPayloadException extends RegistrationIntentException
{
    public function __construct(
        string $message = 'El payload del intent no es valido.',
        string $errorCode = 'REGISTRATION_INTENT_PAYLOAD_INVALID',
    ) {
        parent::__construct($message, $errorCode);
    }
}
