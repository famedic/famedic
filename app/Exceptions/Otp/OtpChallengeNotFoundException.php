<?php

namespace App\Exceptions\Otp;

class OtpChallengeNotFoundException extends OtpChallengeException
{
    public function __construct(string $message = 'No se encontro el desafio OTP.')
    {
        parent::__construct($message, 'OTP_CHALLENGE_NOT_FOUND');
    }
}
