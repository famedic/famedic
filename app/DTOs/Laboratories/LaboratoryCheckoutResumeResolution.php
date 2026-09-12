<?php

namespace App\DTOs\Laboratories;

use App\Models\LaboratoryCheckoutResumeLink;

class LaboratoryCheckoutResumeResolution
{
    public const READY = 'ready';
    public const COMPLETED = 'completed';
    public const UNAUTHENTICATED = 'unauthenticated';
    public const FORBIDDEN = 'forbidden';
    public const INVALID = 'invalid';
    public const EXPIRED = 'expired';
    public const REVOKED = 'revoked';
    public const MISSING_CART = 'missing_cart';
    public const EMPTY_CART = 'empty_cart';

    public function __construct(
        public readonly string $status,
        public readonly ?string $redirectUrl = null,
        public readonly ?LaboratoryCheckoutResumeLink $link = null,
    ) {}

    public function isSafeFailure(): bool
    {
        return in_array($this->status, [
            self::INVALID,
            self::EXPIRED,
            self::REVOKED,
            self::MISSING_CART,
            self::EMPTY_CART,
        ], true);
    }
}
