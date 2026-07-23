<?php

namespace App\Exceptions\Otp;

class OtpChallengeConsumedException extends OtpChallengeException
{
    public function __construct(string $message = 'El desafio OTP ya fue consumido.')
    {
        parent::__construct($message, 'OTP_CHALLENGE_CONSUMED');
    }
}
