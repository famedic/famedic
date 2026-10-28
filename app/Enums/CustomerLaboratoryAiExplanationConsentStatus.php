<?php

namespace App\Enums;

enum CustomerLaboratoryAiExplanationConsentStatus: string
{
    case NotRequested = 'not_requested';
    case Accepted = 'accepted';
    case Declined = 'declined';

    public function allowsGeneration(): bool
    {
        return $this === self::Accepted;
    }
}
