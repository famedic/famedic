<?php

namespace App\Exceptions\Otp;

use Exception;

/**
 * Base OTP challenge exception. Public message must never include the OTP code
 * or full destination.
 */
class OtpChallengeException extends Exception
{
    public function __construct(
        string $message = 'No se pudo procesar el desafio OTP.',
        public readonly string $errorCode = 'OTP_CHALLENGE_ERROR',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
