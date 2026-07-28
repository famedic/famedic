<?php

namespace App\Services\Otp\Delivery;

use App\Exceptions\Otp\OtpTemporaryUnavailableException;
use App\Services\Otp\OtpAbuseKeyHasher;
use Illuminate\Support\Facades\Redis;

final class RedisOtpDeliveryReservationStore implements OtpDeliveryReservationStore
{
    public function __construct(private readonly OtpAbuseKeyHasher $hasher)
    {
    }

    public function reserve(string $operationKey, int $ttlSeconds): bool
    {
        try {
            return (bool) $this->connection()->set($this->key('reservation', $operationKey), '1', 'EX', $ttlSeconds, 'NX');
        } catch (\Throwable) {
            throw new OtpTemporaryUnavailableException;
        }
    }

    public function release(string $operationKey): void
    {
        try {
            $this->connection()->del($this->key('reservation', $operationKey));
        } catch (\Throwable) {
            throw new OtpTemporaryUnavailableException;
        }
    }

    public function markAccepted(string $operationKey, int $ttlSeconds): void
    {
        try {
            $redis = $this->connection();
            $redis->multi();
            $redis->del($this->key('reservation', $operationKey));
            $redis->setex($this->key('accepted', $operationKey), $ttlSeconds, '1');
            $redis->exec();
        } catch (\Throwable) {
            throw new OtpTemporaryUnavailableException;
        }
    }

    public function isAccepted(string $operationKey): bool
    {
        try {
            return (bool) $this->connection()->exists($this->key('accepted', $operationKey));
        } catch (\Throwable) {
            throw new OtpTemporaryUnavailableException;
        }
    }

    public function assertAvailable(): void
    {
        try {
            $this->connection()->ping();
        } catch (\Throwable) {
            throw new OtpTemporaryUnavailableException;
        }
    }

    private function connection(): mixed
    {
        return Redis::connection((string) config('otp.p0a.delivery.redis_connection', 'default'));
    }

    private function key(string $state, string $operationKey): string
    {
        $hash = $this->hasher->hashOpaque('delivery|'.config('otp.p0a.delivery.redis_key_version', 'v1'), $operationKey);

        return implode(':', [
            (string) config('otp.p0a.delivery.redis_key_prefix', 'otp:p0a'),
            (string) config('app.name', 'app'),
            (string) config('app.env', 'production'),
            (string) config('otp.p0a.delivery.redis_key_version', 'v1'),
            'delivery',
            $state,
            $hash,
        ]);
    }
}
