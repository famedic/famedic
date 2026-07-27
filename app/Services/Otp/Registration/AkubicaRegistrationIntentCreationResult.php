<?php

namespace App\Services\Otp\Registration;

use App\Models\AkubicaRegistrationIntent;
use App\Models\OtpChallenge;

/**
 * Internal creation envelope. Plain OTP is for future delivery adapters / tests only.
 * Never serialize plainCode into arrays, logs, or HTTP.
 */
final class AkubicaRegistrationIntentCreationResult
{
    public function __construct(
        public readonly AkubicaRegistrationIntent $intent,
        public readonly OtpChallenge $challenge,
        private readonly string $plainCode,
    ) {
    }

    /**
     * Plain OTP for delivery adapters / internal tests. Never log or API-expose.
     */
    public function plainCode(): string
    {
        return $this->plainCode;
    }

    /**
     * @return array{intent_id: int, challenge_public_id: string, expires_at: string|null}
     */
    public function toSafeArray(): array
    {
        return [
            'intent_id' => (int) $this->intent->id,
            'challenge_public_id' => (string) $this->challenge->public_id,
            'expires_at' => optional($this->intent->expires_at)?->toIso8601String(),
        ];
    }

    public function __toString(): string
    {
        return '[akubica-registration-intent-creation]';
    }
}
