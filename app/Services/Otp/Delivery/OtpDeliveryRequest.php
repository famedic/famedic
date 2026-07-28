<?php

namespace App\Services\Otp\Delivery;

final readonly class OtpDeliveryRequest
{
    public function __construct(
        public string $purpose,
        public string $channel,
        public string $destinationE164OrEmail,
        public string $plainCode,
        public string $correlationId,
        public int $attemptNumber,
        public ?string $from = null,
    ) {
    }

    public function __toString(): string
    {
        return sprintf(
            '[otp-delivery purpose=%s channel=%s correlation=%s attempt=%d]',
            $this->purpose,
            $this->channel,
            $this->correlationId,
            $this->attemptNumber,
        );
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'purpose' => $this->purpose,
            'channel' => $this->channel,
            'correlationId' => $this->correlationId,
            'attemptNumber' => $this->attemptNumber,
            'from' => $this->from === null ? null : '[redacted]',
            'destinationE164OrEmail' => '[redacted]',
            'plainCode' => '[redacted]',
        ];
    }
}
