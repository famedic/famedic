<?php

namespace App\Enums;

enum AkubicaRegistrationIntentStatus: string
{
    case Pending = 'PENDING';
    case Consumed = 'CONSUMED';
    case Expired = 'EXPIRED';
    case Invalidated = 'INVALIDATED';
    case Superseded = 'SUPERSEDED';

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }

    public function allowsPayloadRead(): bool
    {
        return $this === self::Pending;
    }
}
