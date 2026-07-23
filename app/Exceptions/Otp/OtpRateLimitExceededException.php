<?php

namespace App\Exceptions\Otp;

use App\Services\Otp\OtpRateLimitDecision;

/**
 * Thrown when cooldown or request rate limits are exceeded.
 * Carries a domain decision for future HTTP 429 mapping.
 */
class OtpRateLimitExceededException extends OtpChallengeException
{
    public function __construct(
        public readonly OtpRateLimitDecision $decision,
        ?string $message = null,
    ) {
        parent::__construct(
            $message ?? $decision->publicMessage,
            $decision->errorCode ?? 'OTP_RATE_LIMITED',
        );
    }
}
