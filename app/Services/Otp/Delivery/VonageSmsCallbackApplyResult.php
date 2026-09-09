<?php

namespace App\Services\Otp\Delivery;

final readonly class VonageSmsCallbackApplyResult
{
    public function __construct(
        public bool $applied,
        public ?string $skipReason = null,
        public ?string $callbackHost = null,
        public ?int $callbackUrlLength = null,
    ) {}
}
