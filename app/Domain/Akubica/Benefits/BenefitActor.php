<?php

declare(strict_types=1);

namespace App\Domain\Akubica\Benefits;

/** Internal identity projection. Never deserialize from a request. Does not authenticate. */
final readonly class BenefitActor
{
    public function __construct(public int $userId, public string $consumerId, public string $delegationId, public string $channelId = '') {}

    public function namespace(): string
    {
        return hash('sha256', json_encode([$this->userId, $this->consumerId, $this->delegationId, $this->channelId], JSON_THROW_ON_ERROR));
    }
}
