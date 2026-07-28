<?php

namespace App\Services\Otp\Delivery;

final readonly class OtpDeliveryResult
{
    public function __construct(
        public OtpDeliveryResultClass $resultClass,
        public ?string $httpStatusClass,
        public int $attemptNumber,
        public int $durationMs,
        public string $providerAlias,
        public ?string $message = null,
    ) {
    }
}
