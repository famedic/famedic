<?php

namespace App\Exceptions\Otp;

class OtpChallengeMismatchException extends OtpChallengeException
{
    public function __construct(string $message = 'El desafio OTP no coincide con el contexto solicitado.')
    {
        parent::__construct($message, 'OTP_CHALLENGE_MISMATCH');
    }
}
