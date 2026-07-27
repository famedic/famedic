<?php

namespace App\Exceptions\Otp;

/**
 * Registration provision succeeded but Sanctum token issuance failed (post-commit).
 * Recovery: client must use login OTP (P0-A4). Never includes a token.
 */
class RegistrationCompletedLoginRequiredException extends OtpChallengeException
{
    public function __construct(
        string $message = 'El registro se completo. Solicita un codigo de inicio de sesion.',
    ) {
        parent::__construct($message, 'LOGIN_REQUIRED');
    }
}
