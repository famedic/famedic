<?php

namespace App\Exceptions\Api\V1\Auth;

use Exception;

class AuthOtpVerificationException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
