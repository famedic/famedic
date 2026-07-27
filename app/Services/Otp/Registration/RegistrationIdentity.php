<?php

namespace App\Services\Otp\Registration;

/**
 * Pair of normalized registration contacts (email verified later; phone declared).
 */
final readonly class RegistrationIdentity
{
    public function __construct(
        public NormalizedEmail $email,
        public PhoneIdentity $phone,
        public string $fullName,
    ) {
    }

    public function __toString(): string
    {
        return '[registration-identity]';
    }
}
