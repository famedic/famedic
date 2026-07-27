<?php

namespace App\Services\Otp\Registration;

/**
 * Internal collision classification for secure registration.
 * Must never map 1:1 to distinct public HTTP codes (anti-enumeration).
 */
enum RegistrationCollisionKind: string
{
    case Available = 'AVAILABLE';
    case EmailExists = 'EMAIL_EXISTS';
    case PhoneExists = 'PHONE_EXISTS';
    case BothSameUser = 'BOTH_SAME_USER';
    case ContactsBelongToDifferentUsers = 'CONTACTS_BELONG_TO_DIFFERENT_USERS';
    case AmbiguousPhone = 'AMBIGUOUS_PHONE';
    case InvalidIdentity = 'INVALID_IDENTITY';

    public function isCollision(): bool
    {
        return match ($this) {
            self::Available => false,
            default => true,
        };
    }

    /**
     * Public flows treat any non-available outcome as decoy-eligible (except invalid → 422).
     */
    public function shouldUseDecoy(): bool
    {
        return match ($this) {
            self::Available, self::InvalidIdentity, self::AmbiguousPhone => false,
            default => true,
        };
    }
}
