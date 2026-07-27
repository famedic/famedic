<?php

namespace App\Services\Otp\Registration;

/**
 * Canonical phone identity for Akubica secure registration (P0-A5).
 * Does not implement JsonSerializable to avoid accidental PII leaks.
 */
final readonly class PhoneIdentity
{
    public function __construct(
        private string $countryCode,
        private string $nationalNumber,
        private ?string $e164,
        private string $comparisonKey,
    ) {
    }

    public function countryCode(): string
    {
        return $this->countryCode;
    }

    public function nationalNumber(): string
    {
        return $this->nationalNumber;
    }

    public function e164(): ?string
    {
        return $this->e164;
    }

    /**
     * Canonical comparison key (country|national). Not an HMAC — hashing is a later layer.
     */
    public function comparisonKey(): string
    {
        return $this->comparisonKey;
    }

    public function equals(self $other): bool
    {
        return $this->comparisonKey === $other->comparisonKey;
    }

    public function __toString(): string
    {
        return '[phone-identity]';
    }
}
