<?php

namespace App\Contracts\Api\V1\Auth;

use App\Services\Api\V1\Auth\ConsumerAuthorizationContext;

interface ConsumerBindingRepository
{
    /** Authoritative server-side record, never derived from public request fields. */
    public function findByTokenId(int $tokenId): ?ConsumerAuthorizationContext;
}
