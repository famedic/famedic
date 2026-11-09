<?php

namespace App\Services\Api\V1\Auth;

final readonly class ConsumerIdentityResolution
{
    private function __construct(public string $status, public ?ConsumerAuthorizationContext $context = null) {}

    public static function unavailable(): self
    {
        return new self('unavailable');
    }

    public static function unclassified(): self
    {
        return new self('unclassified');
    }

    public static function rejected(): self
    {
        return new self('rejected');
    }

    public static function verified(ConsumerAuthorizationContext $context): self
    {
        return new self('verified', $context);
    }
}
