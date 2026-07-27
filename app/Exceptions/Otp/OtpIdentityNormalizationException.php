<?php

namespace App\Exceptions\Otp;

/**
 * Domain failure while normalizing registration identity (email/phone).
 * Messages must never include the raw input.
 */
class OtpIdentityNormalizationException extends OtpChallengeException
{
    public function __construct(
        string $message = 'La identidad proporcionada no es valida.',
        string $errorCode = 'OTP_IDENTITY_INVALID',
    ) {
        parent::__construct($message, $errorCode);
    }
}
