<?php

namespace App\Services\Otp;

use Illuminate\Support\Carbon;

/**
 * Domain decision prepared for a future HTTP 429 translation layer.
 * Never serializes OTP codes, plaintext IPs, or full destinations.
 */
class OtpRateLimitDecision
{
    public const CODE_COOLDOWN = 'OTP_COOLDOWN';

    public const CODE_RATE_LIMITED = 'OTP_RATE_LIMITED';

    public const CODE_BLOCKED = 'OTP_BLOCKED';

    public const CODE_MAX_ATTEMPTS = 'OTP_MAX_ATTEMPTS';

    public const SCOPE_IDENTITY = 'identity';

    public const SCOPE_IP = 'ip';

    public const SCOPE_BOTH = 'both';

    public const SCOPE_CHALLENGE = 'challenge';

    public const SCOPE_NONE = 'none';

    public function __construct(
        public readonly bool $allowed,
        public readonly ?string $errorCode = null,
        public readonly string $publicMessage = '',
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?Carbon $availableAt = null,
        public readonly string $scope = self::SCOPE_NONE,
        public readonly ?string $purpose = null,
        public readonly ?string $decision = null,
    ) {
    }

    public static function allow(string $decision = 'allowed'): self
    {
        return new self(
            allowed: true,
            decision: $decision,
            scope: self::SCOPE_NONE,
        );
    }

    public static function deny(
        string $errorCode,
        string $publicMessage,
        string $decision,
        string $scope,
        ?int $retryAfterSeconds = null,
        ?Carbon $availableAt = null,
        ?string $purpose = null,
    ): self {
        return new self(
            allowed: false,
            errorCode: $errorCode,
            publicMessage: $publicMessage,
            retryAfterSeconds: $retryAfterSeconds,
            availableAt: $availableAt,
            scope: $scope,
            purpose: $purpose,
            decision: $decision,
        );
    }

    /**
     * Safe payload for future API/exception handlers (no PII).
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'error_code' => $this->errorCode,
            'message' => $this->publicMessage,
            'retry_after' => $this->retryAfterSeconds,
            'available_at' => $this->availableAt?->toIso8601String(),
            'scope' => $this->scope,
            'purpose' => $this->purpose,
            'decision' => $this->decision,
        ];
    }
}
