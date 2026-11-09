<?php

namespace App\Services\Api\V1\Auth;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/** Immutable projection from a future verified backend/binding adapter, not an issuer. */
final readonly class ConsumerAuthorizationContext
{
    public function __construct(
        public string $consumerId,
        public string $consumerKind,
        public string $channelId,
        public string $delegationId,
        public int $userId,
        public int $tokenId,
        public array $consumerCapabilities,
        public array $delegationCapabilities,
        public int $expiresAt,
        public bool $consumerActive,
        public bool $channelActive,
        public bool $delegationActive,
        public bool $bindingActive,
    ) {}

    public function matches(User $user, PersonalAccessToken $token, int $now): bool
    {
        return $this->consumerId !== '' && $this->channelId !== '' && $this->delegationId !== ''
            && $this->userId === (int) $user->getAuthIdentifier()
            && $this->tokenId === (int) $token->getKey()
            && $this->expiresAt > $now
            && $this->consumerActive && $this->channelActive
            && $this->delegationActive && $this->bindingActive;
    }
}
