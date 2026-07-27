<?php

namespace App\Enums;

/**
 * Closed set of invalidation / supersession reasons. Never free-text PII.
 */
enum AkubicaRegistrationIntentInvalidationReason: string
{
    case Superseded = 'superseded';
    case Manual = 'manual';
    case CorruptedPayload = 'corrupted_payload';
    case ChallengeInvalidated = 'challenge_invalidated';
    case InconsistentAssociation = 'inconsistent_association';
}
