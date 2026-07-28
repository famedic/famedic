<?php

namespace App\Services\Otp\Delivery;

interface OtpDeliveryReservationStore
{
    public function reserve(string $operationKey, int $ttlSeconds): bool;

    public function release(string $operationKey): void;

    public function markAccepted(string $operationKey, int $ttlSeconds): void;

    public function isAccepted(string $operationKey): bool;

    /** @throws \App\Exceptions\Otp\OtpTemporaryUnavailableException */
    public function assertAvailable(): void;
}
