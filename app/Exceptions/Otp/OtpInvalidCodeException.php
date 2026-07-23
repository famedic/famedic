<?php

namespace App\Exceptions\Otp;

class OtpInvalidCodeException extends OtpChallengeException
{
    public function __construct(string $message = 'El codigo OTP no es valido.')
    {
        parent::__construct($message, 'OTP_INVALID_CODE');
    }
}
