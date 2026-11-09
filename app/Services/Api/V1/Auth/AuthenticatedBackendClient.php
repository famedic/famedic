<?php

namespace App\Services\Api\V1\Auth;

/** Internal result of an approved authenticator, not a request DTO. */
final readonly class AuthenticatedBackendClient
{
    public function __construct(public string $consumerId, public string $channelId, public int $expiresAt) {}
}
