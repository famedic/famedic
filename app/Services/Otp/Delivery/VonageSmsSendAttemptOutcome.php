<?php

namespace App\Services\Otp\Delivery;

final readonly class VonageSmsSendAttemptOutcome
{
    public function __construct(
        public OtpDeliveryResult $result,
        public bool $callbackFallbackEligible = false,
        public ?int $vonageStatus = null,
        public ?string $vonageErrorText = null,
    ) {}
}
