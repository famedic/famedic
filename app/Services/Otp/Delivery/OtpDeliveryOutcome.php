<?php

namespace App\Services\Otp\Delivery;

/**
 * Result of a register OTP delivery attempt for the caller to decide HTTP outcome.
 */
enum OtpDeliveryOutcome: string
{
    /** Delivery flag off — caller skips channel requirements. */
    case Skipped = 'skipped';

    /** SMS accepted or email fallback accepted. */
    case Succeeded = 'succeeded';

    /** Idempotent suppress (already delivered for this challenge). */
    case DuplicateSuppressed = 'duplicate_suppressed';

    /** No usable channel succeeded; challenge must not remain actionable. */
    case Failed = 'failed';
}
