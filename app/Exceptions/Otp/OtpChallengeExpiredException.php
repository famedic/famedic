<?php

namespace App\Exceptions\Otp;

class OtpChallengeExpiredException extends OtpChallengeException
{
    public function __construct(string $message = 'El desafio OTP ha expirado.')
    {
        parent::__construct($message, 'OTP_CHALLENGE_EXPIRED');
    }
}
