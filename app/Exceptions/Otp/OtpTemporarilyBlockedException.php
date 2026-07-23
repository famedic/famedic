<?php

namespace App\Exceptions\Otp;

use App\Services\Otp\OtpRateLimitDecision;

/**
 * Thrown when identity and/or IP is temporarily blocked.
 * Carries a domain decision for future HTTP 429 mapping.
 */
class OtpTemporarilyBlockedException extends OtpChallengeException
{
    public function __construct(
        public readonly OtpRateLimitDecision $decision,
        ?string $message = null,
    ) {
        parent::__construct(
            $message ?? $decision->publicMessage,
            $decision->errorCode ?? 'OTP_BLOCKED',
        );
    }
}
