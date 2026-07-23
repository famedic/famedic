<?php

namespace App\Services\Otp;

use App\Enums\P0aOtpChannel;
use App\Enums\P0aOtpPurpose;

/**
 * Explicit OTP request context for anti-abuse evaluation.
 * Controllers must map Request → this DTO; domain code never reads Request.
 */
readonly class OtpRequestContext
{
    public function __construct(
        public P0aOtpPurpose $purpose,
        public ?int $userId = null,
        public ?string $subjectType = null,
        public ?string $subjectKey = null,
        public ?string $contextType = null,
        public string|int|null $contextId = null,
        public ?P0aOtpChannel $channel = null,
        /** Client IP as provided by the edge; never persisted in plaintext. */
        public ?string $clientIp = null,
        public ?string $existingChallengePublicId = null,
    ) {
    }
}
