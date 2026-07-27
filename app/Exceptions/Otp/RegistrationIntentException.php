<?php

namespace App\Exceptions\Otp;

/**
 * Base domain exception for registration intents. Messages must never include
 * email, phone, ciphertext, payload, or internal IDs that could leak in logs.
 */
class RegistrationIntentException extends OtpChallengeException
{
    public function __construct(
        string $message = 'No se pudo procesar el intent de registro.',
        string $errorCode = 'REGISTRATION_INTENT_ERROR',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $code, $previous);
    }
}
