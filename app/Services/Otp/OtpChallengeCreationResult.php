<?php

namespace App\Services\Otp;

use App\Models\OtpChallenge;

class OtpChallengeCreationResult
{
    public function __construct(
        public readonly OtpChallenge $challenge,
        private readonly string $plainCode,
    ) {
    }

    /**
     * Plain OTP code for delivery adapters only. Never include in API/model arrays.
     */
    public function plainCode(): string
    {
        return $this->plainCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'challenge' => $this->challenge->toArray(),
            // intentionally omit plainCode
        ];
    }
}
