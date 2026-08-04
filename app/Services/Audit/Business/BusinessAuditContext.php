<?php

namespace App\Services\Audit\Business;

use DateTimeInterface;

/**
 * Explicit normalized context for a business audit write.
 *
 * Built by future domain recorders. Never stores Request, Response, Throwable,
 * session, cookies, headers, bodies, or Eloquent models.
 */
final class BusinessAuditContext
{
    public function __construct(
        private readonly string $channel,
        private readonly BusinessAuditActor $actor,
        private readonly ?string $correlationId = null,
        private readonly ?BusinessAuditSubject $subject = null,
        private readonly DateTimeInterface|string|null $occurredAt = null,
    ) {
        if (! BusinessAuditChannel::isValid($channel)) {
            throw new \InvalidArgumentException('business audit channel is not allowlisted.');
        }
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function actor(): BusinessAuditActor
    {
        return $this->actor;
    }

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    public function subject(): ?BusinessAuditSubject
    {
        return $this->subject;
    }

    public function occurredAt(): DateTimeInterface|string|null
    {
        return $this->occurredAt;
    }
}
