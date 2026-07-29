<?php

namespace App\Exceptions\Otp;

/**
 * OTP delivery could not complete on any usable channel.
 * Public message must never include the OTP, destination, or provider secrets.
 */
class OtpDeliveryFailedException extends OtpChallengeException
{
    public function __construct(
        string $message = 'No se pudo enviar el codigo de verificacion.',
        string $errorCode = 'DELIVERY_FAILED',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $code, $previous);
    }
}
