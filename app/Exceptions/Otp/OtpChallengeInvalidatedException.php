<?php

namespace App\Exceptions\Otp;

class OtpChallengeInvalidatedException extends OtpChallengeException
{
    public function __construct(string $message = 'El desafio OTP fue invalidado.')
    {
        parent::__construct($message, 'OTP_CHALLENGE_INVALIDATED');
    }
}
