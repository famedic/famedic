<?php

use App\Exceptions\Otp\OtpTemporaryUnavailableException;
use App\Services\Otp\Delivery\RedisOtpDeliveryReservationStore;
use App\Services\Otp\OtpAbuseKeyHasher;

test('redis otp delivery reservation store reserves releases accepts and expires keys', function () {
    config()->set('database.redis.default.host', env('REDIS_HOST', 'redis'));
    config()->set('database.redis.default.port', (int) env('REDIS_PORT', 6379));
    config()->set('database.redis.default.password', env('REDIS_PASSWORD') === 'null' ? null : env('REDIS_PASSWORD'));
    config()->set('otp.p0a.delivery.redis_connection', 'default');
    config()->set('otp.p0a.delivery.redis_key_prefix', 'otp:p0a:test');

    $store = new RedisOtpDeliveryReservationStore(app(OtpAbuseKeyHasher::class));
    $operationKey = 'redis-reservation-test-'.bin2hex(random_bytes(8));

    try {
        $store->assertAvailable();
        $this->addToAssertionCount(1);
    } catch (OtpTemporaryUnavailableException) {
        $this->markTestSkipped('Redis is not available for the focal reservation-store test.');
    }

    try {
        expect($store->reserve($operationKey, 5))->toBeTrue();
        expect($store->reserve($operationKey, 5))->toBeFalse();

        $store->release($operationKey);
        expect($store->reserve($operationKey, 5))->toBeTrue();

        $store->markAccepted($operationKey, 1);
        expect($store->isAccepted($operationKey))->toBeTrue();

        sleep(2);
        expect($store->isAccepted($operationKey))->toBeFalse();
    } finally {
        $store->release($operationKey);
    }
});
