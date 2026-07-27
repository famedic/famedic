<?php

namespace App\Exceptions\Otp;

/**
 * Invalid OTP feature-flag combination (e.g. login OTP on without anti-abuse).
 * Must not fail open and must not expose internals to API clients.
 */
class OtpConfigurationException extends OtpChallengeException
{
    public function __construct(
        string $message = 'La configuracion OTP no es valida para este flujo.',
        string $errorCode = 'OTP_CONFIGURATION_INVALID',
    ) {
        parent::__construct($message, $errorCode);
    }
}
