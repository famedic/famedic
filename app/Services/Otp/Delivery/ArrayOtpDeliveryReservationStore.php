<?php

namespace App\Services\Otp\Delivery;

use App\Exceptions\Otp\OtpTemporaryUnavailableException;

/** Explicit test double; never selected as a production fallback. */
final class ArrayOtpDeliveryReservationStore implements OtpDeliveryReservationStore
{
    /** @var array<string, int> */
    private array $keys = [];

    public bool $unavailable = false;

    public function reserve(string $operationKey, int $ttlSeconds): bool
    {
        $this->assertAvailable();
        $this->purge();
        if (isset($this->keys['reservation:'.$operationKey]) || isset($this->keys['accepted:'.$operationKey])) {
            return false;
        }
        $this->keys['reservation:'.$operationKey] = time() + $ttlSeconds;

        return true;
    }

    public function release(string $operationKey): void
    {
        unset($this->keys['reservation:'.$operationKey]);
    }

    public function markAccepted(string $operationKey, int $ttlSeconds): void
    {
        $this->assertAvailable();
        unset($this->keys['reservation:'.$operationKey]);
        $this->keys['accepted:'.$operationKey] = time() + $ttlSeconds;
    }

    public function isAccepted(string $operationKey): bool
    {
        $this->assertAvailable();
        $this->purge();

        return isset($this->keys['accepted:'.$operationKey]);
    }

    public function assertAvailable(): void
    {
        if ($this->unavailable) {
            throw new OtpTemporaryUnavailableException;
        }
    }

    private function purge(): void
    {
        $now = time();
        foreach ($this->keys as $key => $expires) {
            if ($expires <= $now) {
                unset($this->keys[$key]);
            }
        }
    }
}
