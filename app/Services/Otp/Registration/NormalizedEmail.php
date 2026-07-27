<?php

namespace App\Services\Otp\Registration;

/**
 * Canonical email identity for Akubica secure registration (P0-A5).
 * Never log or dump the value via __toString.
 */
final readonly class NormalizedEmail
{
    public function __construct(
        private string $value,
    ) {
    }

    public function value(): string
    {
        return $this->value;
    }

    /**
     * Stable key for equality / future anti-abuse HMAC input (not hashed here).
     */
    public function comparisonKey(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return '[normalized-email]';
    }
}
