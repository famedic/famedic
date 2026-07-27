<?php

namespace App\Exceptions\Otp;

/**
 * Transient DB contention (deadlock / lock wait). Safe public contract only.
 */
class OtpTemporaryUnavailableException extends OtpChallengeException
{
    public function __construct(
        string $message = 'El servicio OTP esta temporalmente no disponible. Intenta de nuevo.',
        public readonly int $retryAfterSeconds = 1,
    ) {
        parent::__construct($message, 'OTP_TEMPORARY_UNAVAILABLE');
    }
}
