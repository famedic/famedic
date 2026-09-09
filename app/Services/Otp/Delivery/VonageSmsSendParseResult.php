<?php

namespace App\Services\Otp\Delivery;

final readonly class VonageSmsSendParseResult
{
    public function __construct(
        public bool $accepted,
        public ?string $messageId,
        public ?int $vonageStatus,
        public ?string $errorText,
        public bool $interpretable = true,
    ) {}
}
