<?php

namespace App\Services\Otp\Registration;

/**
 * Internal result of collision resolution. Never serialize to API responses.
 */
final readonly class RegistrationCollisionResult
{
    /**
     * @param  list<int>  $matchingUserIds  Internal only; never expose publicly.
     */
    public function __construct(
        public RegistrationCollisionKind $kind,
        public array $matchingUserIds = [],
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->kind === RegistrationCollisionKind::Available;
    }

    public function __toString(): string
    {
        return '[registration-collision:'.$this->kind->value.']';
    }
}
