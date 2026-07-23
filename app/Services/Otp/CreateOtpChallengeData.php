<?php

namespace App\Services\Otp;

use App\Enums\P0aOtpChannel;
use App\Enums\P0aOtpPurpose;

readonly class CreateOtpChallengeData
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function __construct(
        public P0aOtpPurpose $purpose,
        public P0aOtpChannel $channel,
        public int $ttlMinutes,
        public ?int $userId = null,
        public ?string $subjectType = null,
        public ?string $subjectKey = null,
        public ?string $destinationNormalized = null,
        public ?string $destinationMasked = null,
        public ?string $contextType = null,
        public string|int|null $contextId = null,
        public bool $invalidatePreviousActive = true,
        public ?array $meta = null,
        public ?int $maxAttempts = null,
    ) {
    }
}
