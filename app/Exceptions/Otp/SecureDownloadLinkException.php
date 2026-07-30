<?php

namespace App\Exceptions\Otp;

class SecureDownloadLinkException extends OtpChallengeException
{
    public function __construct(
        string $message,
        string $errorCode,
        public readonly int $httpStatus = 400,
    ) {
        parent::__construct($message, $errorCode);
    }
}
